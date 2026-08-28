<?php

namespace App\Http\Controllers;

use App\Models\CompanyProfile;
use App\Models\DividendDeclaration;
use App\Models\DividendDistribution;
use App\Services\DividendService;
use App\Services\FrankingService;
use App\Services\IfrsPosting;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;

/**
 * Dividend declaration lifecycle: draft entry, distribution calculation,
 * approval (posts Dr Dividends Paid / Cr Dividends Payable and checks the
 * franking balance), the manual payment schedule (screen + CSV — no
 * in-system payments), recording the settled run (posts Dr Dividends
 * Payable / Cr Bank, creates the franking debit, emails statements) and
 * cancellation with ledger reversal.
 */
class DividendDeclarationController extends Controller
{
    public function index(Request $request)
    {
        $declarations = DividendDeclaration::query()
            ->with('shareClass')
            ->when($request->status, fn ($q, $status) => $q->where('status', $status))
            ->orderByDesc('declaration_date')
            ->orderByDesc('id')
            ->paginate(20);

        return view('dividends.index', ['declarations' => $declarations]);
    }

    public function create()
    {
        [$entity, $profile] = $this->entityAndProfile();
        abort_unless((bool) $profile, 404, 'Maintain the company profile and share classes first.');

        return view('dividends.create', [
            'shareClasses' => $profile->shareClasses()->active()->orderBy('code')->get(),
            'frankingRate' => config('dividends.franking_credit_rate'),
            'frankingPercentage' => config('dividends.default_franking_percentage'),
            'availableFranking' => FrankingService::availableBalance(),
        ]);
    }

    public function store(Request $request)
    {
        [$entity, $profile] = $this->entityAndProfile();
        abort_unless((bool) $entity && (bool) $profile, 404, 'Maintain the company profile first.');

        $validated = $request->validate([
            'declaration_date' => ['required', 'date'],
            'share_class_id' => ['required', 'integer', 'exists:share_classes,id'],
            'dividend_type' => ['required', 'in:' . implode(',', array_keys(DividendDeclaration::dividendTypes()))],
            'amount_per_share' => ['required', 'numeric', 'min:0', 'not_in:0'],
            'franking_percentage' => ['required', 'numeric', 'between:0,100'],
            'franking_credit_rate' => ['required', 'numeric', 'between:0.01,99.99'],
            'payment_date' => ['required', 'date', 'after_or_equal:declaration_date'],
            'books_close_date' => ['required', 'date', 'before_or_equal:payment_date'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        DividendDeclaration::createWithUniqueNumber([
            'entity_id' => $entity->id,
            'financial_year' => FrankingService::financialYearFor($validated['declaration_date'], $entity),
            'dividend_type' => $validated['dividend_type'],
            'amount_per_share' => $validated['amount_per_share'],
            'franking_percentage' => $validated['franking_percentage'],
            'franking_credit_rate' => $validated['franking_credit_rate'],
            'declaration_date' => $validated['declaration_date'],
            'payment_date' => $validated['payment_date'],
            'books_close_date' => $validated['books_close_date'],
            'share_class_id' => $validated['share_class_id'],
            'notes' => $validated['notes'] ?? null,
            'created_by' => $request->user()->id,
        ]);

        return redirect()->route('dividends.index')->with('success', 'Dividend declaration created as draft.');
    }

    public function show(DividendDeclaration $declaration)
    {
        $declaration->load('shareClass', 'distributions.shareholder', 'approvedBy');

        return view('dividends.show', [
            'declaration' => $declaration,
            'availableFranking' => FrankingService::availableBalance(),
            'frankingDeficit' => config('dividends.enable_fdt_warning')
                && FrankingService::hasDeficit($declaration->financial_year),
        ]);
    }

    public function edit(DividendDeclaration $declaration)
    {
        abort_unless($declaration->status === DividendDeclaration::STATUS_DRAFT, 403, 'Only draft declarations can be edited.');

        [, $profile] = $this->entityAndProfile();

        return view('dividends.edit', [
            'declaration' => $declaration,
            'shareClasses' => $profile?->shareClasses()->active()->orderBy('code')->get() ?? collect(),
        ]);
    }

    public function update(Request $request, DividendDeclaration $declaration)
    {
        abort_unless($declaration->status === DividendDeclaration::STATUS_DRAFT, 403, 'Only draft declarations can be edited.');

        $validated = $request->validate([
            'declaration_date' => ['required', 'date'],
            'share_class_id' => ['required', 'integer', 'exists:share_classes,id'],
            'dividend_type' => ['required', 'in:' . implode(',', array_keys(DividendDeclaration::dividendTypes()))],
            'amount_per_share' => ['required', 'numeric', 'min:0', 'not_in:0'],
            'franking_percentage' => ['required', 'numeric', 'between:0,100'],
            'franking_credit_rate' => ['required', 'numeric', 'between:0.01,99.99'],
            'payment_date' => ['required', 'date', 'after_or_equal:declaration_date'],
            'books_close_date' => ['required', 'date', 'before_or_equal:payment_date'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $entity = IfrsPosting::resolveEntity();

        $declaration->update([
            ...collect($validated)->except('declaration_date')->all(),
            'declaration_date' => $validated['declaration_date'],
            'financial_year' => FrankingService::financialYearFor($validated['declaration_date'], $entity),
        ]);

        return redirect()->route('dividends.show', $declaration)->with('success', 'Declaration updated.');
    }

    /**
     * (Re)build the distribution lines from the shareholdings ledger.
     */
    public function calculate(Request $request, DividendDeclaration $declaration)
    {
        try {
            $count = DividendService::generateDistributions($declaration);
        } catch (\Throwable $e) {
            return redirect()->route('dividends.show', $declaration)->with('error', $e->getMessage());
        }

        return redirect()->route('dividends.show', $declaration)
            ->with('success', "Calculated {$count} distribution line(s) as at the books-close date.");
    }

    public function approve(Request $request, DividendDeclaration $declaration)
    {
        try {
            DividendService::approve($declaration);
        } catch (\Throwable $e) {
            return redirect()->route('dividends.show', $declaration)->with('error', $e->getMessage());
        }

        return redirect()->route('dividends.show', $declaration)
            ->with('success', 'Declaration approved and posted: Dr Dividends Paid / Cr Dividends Payable.');
    }

    /**
     * The manual payment run has been executed at the bank — post the
     * payment journal, create the franking debit, then email statements.
     */
    public function recordPayment(Request $request, DividendDeclaration $declaration)
    {
        try {
            DividendService::recordPayment($declaration);
        } catch (\Throwable $e) {
            return redirect()->route('dividends.show', $declaration)->with('error', $e->getMessage());
        }

        $statements = DividendService::sendStatements($declaration);
        $message = 'Payment recorded: Dr Dividends Payable / Cr Bank posted and franking debit created. '
            . "Statements emailed: {$statements['sent']}.";
        if ($statements['missing_email'] || $statements['failed']) {
            $message .= " Skipped (no email): {$statements['missing_email']}, failed: {$statements['failed']}.";
        }

        return redirect()->route('dividends.show', $declaration)->with('success', $message);
    }

    public function sendStatements(Request $request, DividendDeclaration $declaration)
    {
        $force = $request->boolean('force');
        $results = DividendService::sendStatements($declaration, force: $force);

        $message = "Statements emailed: {$results['sent']}.";
        if ($results['missing_email'] || $results['failed']) {
            $message .= " Skipped (no email): {$results['missing_email']}, failed: {$results['failed']}.";
        }

        return redirect()->route('dividends.show', $declaration)->with('success', $message);
    }

    public function cancel(Request $request, DividendDeclaration $declaration)
    {
        try {
            DividendService::cancel($declaration);
        } catch (\Throwable $e) {
            return redirect()->route('dividends.show', $declaration)->with('error', $e->getMessage());
        }

        return redirect()->route('dividends.show', $declaration)
            ->with('success', 'Declaration cancelled' .
                ($declaration->ifrs_declaration_transaction_id ? ' and the ledger entry reversed.' : '.'));
    }

    /**
     * CSV of the payment schedule for manual entry at the bank.
     */
    public function paymentScheduleCsv(Request $request, DividendDeclaration $declaration)
    {
        abort_unless($declaration->status !== DividendDeclaration::STATUS_DRAFT, 403, 'Approve the declaration first.');

        $rows = [[
            'Shareholder', 'Email', 'BSB', 'Account Number', 'Account Name',
            'Net Payment', 'Shares', 'Payment Reference',
        ]];

        foreach ($declaration->distributions()->with('shareholder')->orderBy('id')->get() as $distribution) {
            $rows[] = [
                $distribution->shareholder_name,
                $distribution->shareholder?->email ?? '',
                $distribution->shareholder?->bank_bsb ?? '',
                $distribution->shareholder?->bank_account_number ?? '',
                $distribution->shareholder?->bank_account_name ?? '',
                number_format((float) $distribution->net_payment, 2, '.', ''),
                $distribution->shares_eligible,
                $distribution->payment_reference ?? '',
            ];
        }

        $callback = function () use ($rows) {
            $out = fopen('php://output', 'w');
            foreach ($rows as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        };

        return Response::streamDownload(
            $callback,
            "Dividend-Payment-Schedule-{$declaration->declaration_number}.csv",
            ['Content-Type' => 'text/csv'],
        );
    }

    /**
     * On-screen statement PDF (print/download) for one distribution.
     */
    public function statementPdf(Request $request, DividendDistribution $distribution)
    {
        $pdf = Pdf::loadView('reports.pdf.dividend-statement', [
            'distribution' => $distribution->load('declaration.shareClass', 'shareholder'),
            'companyName' => \IFRS\Models\Entity::find($distribution->declaration->entity_id)?->name ?? config('app.name'),
            'companyAbn' => CompanyProfile::effectiveAbn($distribution->declaration->entity_id),
        ]);

        return $pdf->download('Dividend-Statement-' . $distribution->declaration->declaration_number
            . '-' . $distribution->company_shareholder_id . '.pdf');
    }

    protected function entityAndProfile(): array
    {
        $entity = IfrsPosting::resolveEntity();
        $profile = $entity ? CompanyProfile::where('entity_id', $entity->id)->first() : null;

        return [$entity, $profile];
    }
}
