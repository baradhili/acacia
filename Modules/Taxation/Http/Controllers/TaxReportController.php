<?php

namespace Modules\Taxation\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesReportingContext;
use App\Http\Controllers\Controller;
use App\Models\BillPayment;
use App\Models\CompanyProfile;
use App\Models\FiscalYearClose;
use App\Models\Payment;
use App\Services\FiscalYearService;
use App\Services\OpeningBalances;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use IFRS\Models\Account;
use IFRS\Models\Balance;
use IFRS\Models\Entity;
use IFRS\Models\ReportingPeriod;
use IFRS\Scopes\EntityScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;
use Modules\Payroll\Models\PayRun;
use Modules\Shares\Models\DividendDeclaration;
use Modules\Shares\Services\FrankingService;
use Modules\Taxation\Exports\BasExport;
use Modules\Taxation\Exports\CompanyTaxExport;
use Modules\Taxation\Models\BasSettlement;
use Modules\Taxation\Models\BasStatement;
use Modules\Taxation\Services\BasSettlementService;

/**
 * Australian statutory reporting on the cash basis the ledger keeps:
 * the GST report, the quarterly BAS (with lodgement freezing) and the
 * ATO Company Tax Return. Soft-depends on Payroll (W1/W2) and Shares
 * (dividend labels, franking balances) via class_exists.
 */
class TaxReportController extends Controller
{
    use ResolvesReportingContext;

    /**
     * GST collected/paid from the accounts the entity's Vats post to
     * (seeded "GST 10%" → 2200 GST Payable, "GST Input 10%" → 430 GST
     * Receivable), classified by account role: 2200's legs net to GST
     * collected (its debits are credit-note refund reversals, not GST
     * paid) and 430's legs net to GST paid (its credits are supplier
     * refund reversals, not GST collected). When the input Vat is not
     * seeded, BillPayment::purchaseGstVat() falls back to the output
     * Vat (G), purchases post through 2200 too, and roles on that
     * shared account cannot be separated — it keeps the per-side split
     * (credits collected, debits paid). Cash basis — the exact legs the
     * payment postings write, so the GST, BAS and company tax reports
     * all tie to the ledger.
     */
    protected function ledgerGst(Entity $entity, Carbon $startDate, Carbon $endDate): object
    {
        $vatAccounts = DB::table('ifrs_vats')
            ->where('entity_id', $entity->id)
            ->whereNotNull('account_id')
            ->get(['code', 'account_id']);

        if ($vatAccounts->isEmpty()) {
            return (object) ['collected' => 0.0, 'paid' => 0.0];
        }

        $inputCode = config('subscriptions.purchase_gst_vat_code', 'I');

        // Role per account from the Vat code that posts to it: the input
        // code carries purchase GST, anything else output GST. An account
        // both kinds post to is shared and reverts to the per-side split.
        $roles = [];
        foreach ($vatAccounts as $vat) {
            $role = $vat->code === $inputCode ? 'input' : 'output';
            $roles[$vat->account_id] = isset($roles[$vat->account_id]) && $roles[$vat->account_id] !== $role
                ? 'shared'
                : $role;
        }

        // Purchases normally post through the input Vat; the fallback to
        // the output Vat (unmigrated databases without the input Vat)
        // mixes both roles onto its account.
        $purchaseVat = BillPayment::purchaseGstVat($entity);
        if ($purchaseVat && $purchaseVat->account_id !== null && $purchaseVat->code !== $inputCode) {
            $roles[$purchaseVat->account_id] = 'shared';
        }

        $legs = DB::table('ifrs_ledgers')
            ->whereIn('post_account', array_keys($roles))
            ->whereBetween('posting_date', [$startDate, $endDate])
            ->whereNull('deleted_at')
            ->groupBy('post_account')
            ->selectRaw("post_account,
                SUM(CASE WHEN entry_type = '".Balance::CREDIT."' THEN amount ELSE 0 END) as credits,
                SUM(CASE WHEN entry_type = '".Balance::DEBIT."' THEN amount ELSE 0 END) as debits")
            ->get();

        $collected = 0.0;
        $paid = 0.0;
        foreach ($legs as $leg) {
            $role = $roles[$leg->post_account] ?? 'shared';
            if ($role === 'output') {
                $collected += (float) $leg->credits - (float) $leg->debits;
            } elseif ($role === 'input') {
                $paid += (float) $leg->debits - (float) $leg->credits;
            } else {
                $collected += (float) $leg->credits;
                $paid += (float) $leg->debits;
            }
        }

        return (object) [
            'collected' => $collected,
            'paid' => $paid,
        ];
    }

    public function gstReport(Request $request)
    {
        $startDate = $request->get('start_date')
            ? Carbon::parse($request->start_date)->startOfDay()
            : Carbon::now()->startOfMonth();

        $endDate = $request->get('end_date')
            ? Carbon::parse($request->end_date)->endOfDay()
            : Carbon::now()->endOfDay();

        $entity = $this->ifrsEntity();

        // Cash basis: GST figures come from the Vat account ledger legs
        // the payment postings write — the same basis as the ATO company
        // tax report — not from the invoice/bill subledger by issue date,
        // which recognised GST on issuance and diverged from the ledger
        // whenever invoices/bills were unpaid at period end.
        $gst = $this->ledgerGst($entity, $startDate, $endDate);
        $gstCollected = $gst->collected;
        $gstPaid = $gst->paid;

        // Money actually banked/paid out behind those legs. Only posted
        // payments count, so the report always ties to the ledger;
        // unposted ones appear once ifrs:post-payments backfills them,
        // refunds net through their negative amounts and voided payments
        // never posted.
        $totalReceipts = (float) Payment::whereBetween('payment_date', [$startDate, $endDate])
            ->where('status', '!=', Payment::STATUS_VOID)
            ->whereNotNull('ifrs_receipt_id')
            ->sum('amount');
        $totalPayments = (float) BillPayment::whereBetween('payment_date', [$startDate, $endDate])
            ->where('status', '!=', BillPayment::STATUS_VOID)
            ->whereNotNull('ifrs_payment_id')
            ->sum('amount');

        $netGst = $gstCollected - $gstPaid;

        // The unlodged position at the report's end date — the balances
        // sitting on the GST accounts awaiting settlement, both sides
        // (the BAS settlement screen nets exactly these).
        $unlodged = $entity
            ? app(BasSettlementService::class)->position($endDate)
            : ['payable' => 0.0, 'receivable' => 0.0, 'net' => 0.0];

        return view('taxation.gst', compact(
            'startDate', 'endDate',
            'gstCollected', 'totalReceipts',
            'gstPaid', 'totalPayments',
            'netGst',
            'unlodged'
        ));
    }

    /**
     * BAS (Business Activity Statement) Report — all four quarters of
     * an Australian financial year (1 July – 30 June).
     */
    public function bas(Request $request)
    {
        // FY named by its June year-end: FY2026 = Jul 2025 – Jun 2026.
        // Derived from the entity's year_start so a non-July FY (or a
        // later change to year_start) cannot drift from the ledger.
        $currentFyEnd = ReportingPeriod::year(now(), $this->ifrsEntity()) + 1;
        $fyEnd = (int) $request->get('fy', $currentFyEnd);

        $statement = $this->buildBasStatement($fyEnd);
        $priorYear = $this->priorYearUnsettled($fyEnd);
        $availableFys = range($currentFyEnd, $currentFyEnd - 5);

        return view('taxation.bas', compact(
            'fyEnd', 'currentFyEnd', 'availableFys', 'statement', 'priorYear'
        ));
    }

    /**
     * The previous financial year's BAS totals when its net has not been
     * settled — the first line of the report table, so a year that was
     * never paid to the ATO cannot quietly disappear. Null (no line)
     * when the prior year netted to nothing, or once a GST settlement
     * has covered through that year's end — the balance-side action the
     * settlement screen records, which one catch-up payment clears
     * across any number of quarters.
     *
     * @return array{fy_end: int, start: Carbon, end: Carbon, totals: array}|null
     */
    protected function priorYearUnsettled(int $fyEnd): ?array
    {
        $prior = $this->buildBasStatement($fyEnd - 1);
        $net = round((float) $prior['totals']['net'], 2);

        if (abs($net) < 0.005) {
            return null;
        }

        $settled = BasSettlement::query()
            ->where('entity_id', $this->ifrsEntity()->id)
            ->where('type', BasSettlement::TYPE_GST)
            ->whereNull('reversed_at')
            ->whereDate('as_at', '>=', $prior['fyEnd']->toDateString())
            ->exists();

        return $settled ? null : [
            'fy_end' => $fyEnd - 1,
            'start' => $prior['fyStart'],
            'end' => $prior['fyEnd'],
            'totals' => $prior['totals'],
        ];
    }

    /**
     * Freeze a BAS quarter at lodgement: capture the report's live
     * figures for that quarter so later backdated postings can't
     * rewrite a lodged BAS. Freezing again recaptures the live figures
     * (use unfreeze to return to recomputation). Admin/accountant only
     * (route middleware).
     */
    public function freezeBasQuarter(Request $request)
    {
        $validated = $request->validate([
            'fy' => ['required', 'integer'],
            'quarter' => ['required', 'integer', 'min:1', 'max:4'],
        ]);

        $statement = $this->buildBasStatement($validated['fy'], $validated['quarter']);
        $quarter = $statement['quarters'][$validated['quarter'] - 1] ?? null;
        abort_unless((bool) $quarter, 404, 'Unknown BAS quarter.');

        if ($quarter['end']->greaterThan(now())) {
            return redirect()->route('reports.bas', ['fy' => $validated['fy']])
                ->with('error', sprintf('Q%d has not ended yet — nothing to lodge.', $validated['quarter']));
        }

        BasStatement::updateOrCreate(
            [
                'entity_id' => $this->ifrsEntity()->id,
                'fy_end' => $validated['fy'],
                'quarter' => $validated['quarter'],
            ],
            [
                'period_start' => $quarter['start']->toDateString(),
                'period_end' => $quarter['end']->toDateString(),
                'g1' => $quarter['g1'],
                'g10' => $quarter['g10'],
                'g11' => $quarter['g11'],
                'w1' => $quarter['w1'],
                'w2' => $quarter['w2'],
                'gst_sales' => $quarter['gst_sales'],
                'gst_purchases' => $quarter['gst_purchases'],
                'net' => $quarter['net'],
                'lodged_at' => now(),
                'lodged_by' => $request->user()->id,
            ],
        );

        return redirect()->route('reports.bas', ['fy' => $validated['fy']])
            ->with('success', sprintf('Q%d frozen at lodgement — its figures no longer recompute from the ledger.', $validated['quarter']));
    }

    /**
     * Unfreeze a lodged quarter — delete the frozen record and return
     * the quarter to live recomputation. Admin/accountant only, and
     * only for the caller's own entity: the route binding resolves by
     * id alone, so the entity is verified here (404 rather than 403 so
     * other entities' statement ids are not confirmed to exist).
     */
    public function unfreezeBasQuarter(BasStatement $statement)
    {
        abort_unless($statement->entity_id === $this->ifrsEntity()->id, 404, 'Unknown BAS statement.');

        $fyEnd = $statement->fy_end;
        $label = $statement->label();
        $statement->delete();

        return redirect()->route('reports.bas', ['fy' => $fyEnd])
            ->with('success', "{$label} unfrozen — the quarter recomputes from the ledger again.");
    }

    /**
     * Quarterly BAS figures for the financial year ending 30 June
     * $fyEnd, on the cash basis the ledger keeps: G1 is posted client
     * payments (GST-inclusive, refunds netting via negative amounts),
     * 1A/1B are the Vat account ledger legs (ledgerGst()), G10/G11 are
     * bill payment allocations apportioned across bill lines via
     * BillPayment::allocationGroups() — the same shares the postings
     * use — split into capital (non-current-asset accounts) and
     * non-capital, and W1/W2 are processed pay runs' gross and withheld
     * by pay day. Every figure ties to the books because only posted
     * payments and processed runs count; unposted ones appear once
     * ifrs:post-payments backfills them.
     */
    protected function buildBasStatement(int $fyEnd, ?int $withoutFrozenQuarter = null): array
    {
        // FY boundaries from the entity's year_start ($fyEnd is the FY's
        // ending calendar year; the FY label is one less). Identical to
        // the previous hard-coded Jul–Jun for the default July start.
        $entity = $this->ifrsEntity();
        ['start' => $fyStart, 'end' => $fyEndDate] = (new FiscalYearService)->bounds($entity, $fyEnd - 1);
        $fyStart = $fyStart->startOfDay();
        $fyEndDate = $fyEndDate->copy()->endOfDay();

        // BAS quarters: consecutive three-month blocks of the FY, in
        // whatever month it starts (Q1 Jul-Sep, Q2 Oct-Dec, Q3 Jan-Mar,
        // Q4 Apr-Jun for the default July start).
        $quarterOf = fn ($date) => intdiv(
            $fyStart->copy()->startOfMonth()->diffInMonths(Carbon::parse($date)->startOfMonth()),
            3
        );

        $quarters = [];
        foreach ([0, 1, 2, 3] as $i) {
            $start = $fyStart->copy()->addMonths($i * 3)->startOfDay();
            $quarters[$i] = [
                'label' => sprintf('Q%d (%s-%s)', $i + 1, $start->format('M'), $start->copy()->addMonths(2)->format('M')),
                'start' => $start,
                'end' => $start->copy()->addMonths(3)->subDay()->endOfDay(),
                'g1' => 0.0,
                'gst_sales' => 0.0,
                'g10' => 0.0,
                'g11' => 0.0,
                'w1' => 0.0,
                'w2' => 0.0,
                'gst_purchases' => 0.0,
            ];
        }

        // G1 — posted client payments, GST-inclusive (refunds subtract).
        $payments = Payment::whereBetween('payment_date', [$fyStart, $fyEndDate])
            ->where('status', '!=', Payment::STATUS_VOID)
            ->whereNotNull('ifrs_receipt_id')
            ->get();
        foreach ($payments as $payment) {
            $quarters[$quarterOf($payment->payment_date)]['g1'] += (float) $payment->amount;
        }

        // 1A/1B — the GST ledger legs the postings wrote, per quarter.
        foreach ($quarters as $i => $quarter) {
            $gst = $this->ledgerGst($entity, $quarter['start'], $quarter['end']);
            $quarters[$i]['gst_sales'] = $gst->collected;
            $quarters[$i]['gst_purchases'] = $gst->paid;
        }

        // G10/G11 — supplier payments apportioned across their bills'
        // lines. A line categorised to a non-current-asset account is a
        // capital purchase; everything else is non-capital (GST credits
        // in 1B cover both kinds).
        $accountTypes = DB::table('ifrs_accounts')
            ->where('entity_id', $entity->id)
            ->pluck('account_type', 'id');
        $defaultExpenseAccount = Account::withoutGlobalScope(EntityScope::class)
            ->where('entity_id', $entity->id)
            ->where('code', BillPayment::IFRS_DEFAULT_EXPENSE_ACCOUNT_CODE)
            ->first();

        $billPayments = BillPayment::whereBetween('payment_date', [$fyStart, $fyEndDate])
            ->where('status', '!=', BillPayment::STATUS_VOID)
            ->whereNotNull('ifrs_payment_id')
            ->with('allocations.bill.items')
            ->get();
        foreach ($billPayments as $billPayment) {
            $i = $quarterOf($billPayment->payment_date);
            foreach ($billPayment->allocations as $allocation) {
                $bill = $allocation->bill;
                if (! $bill) {
                    continue;
                }
                foreach (BillPayment::allocationGroups($bill, (float) $allocation->amount, $defaultExpenseAccount) as $key => $cents) {
                    [$accountId] = explode('-', $key);
                    $column = ($accountTypes[$accountId] ?? null) === Account::NON_CURRENT_ASSET ? 'g10' : 'g11';
                    $quarters[$i][$column] += $cents / 100;
                }
            }
        }

        // W1/W2 — processed pay runs' gross (W1) and PAYG withheld (W2),
        // attributed by pay day like every other label here. The withheld
        // leg of each run is the same Cr 2210 the BAS settlement screen
        // nets, so W2 matches what settling PAYG withholding clears.
        // Soft dependency: the labels only exist when the Payroll module
        // is enabled (its provider registers routes, tables and the
        // PSI/BAS label coverage).
        $payRunClass = PayRun::class;
        if (class_exists($payRunClass)) {
            $payRuns = $payRunClass::where('entity_id', $entity->id)
                ->whereBetween('payment_date', [$fyStart, $fyEndDate])
                ->where('status', $payRunClass::STATUS_PROCESSED)
                ->with('payslips')
                ->get();
        } else {
            $payRuns = collect();
        }
        foreach ($payRuns as $run) {
            $i = $quarterOf($run->payment_date);
            $quarters[$i]['w1'] += (float) $run->payslips->sum('gross');
            $quarters[$i]['w2'] += (float) $run->payslips->sum('payg_withheld');
        }

        foreach ($quarters as &$q) {
            $q['net'] = $q['gst_sales'] - $q['gst_purchases'];
        }
        unset($q);

        // A quarter frozen at lodgement keeps its lodged figures: the
        // BAS was computed and sent with them, so backdated postings
        // must not rewrite it. Totals below pick the frozen values up.
        $frozen = BasStatement::where('entity_id', $entity->id)
            ->where('fy_end', $fyEnd)
            ->get()
            ->keyBy('quarter');

        foreach ($quarters as $i => &$q) {
            if ($withoutFrozenQuarter === $i + 1) {
                continue; // freezing (again) recaptures live figures
            }

            if (($statement = $frozen->get($i + 1)) !== null) {
                $q = array_merge($q, $statement->frozenFigures(), [
                    'frozen_at' => $statement->lodged_at,
                    'frozen_id' => $statement->id,
                ]);
            }
        }
        unset($q);

        $totals = [
            'g1' => array_sum(array_column($quarters, 'g1')),
            'gst_sales' => array_sum(array_column($quarters, 'gst_sales')),
            'g10' => array_sum(array_column($quarters, 'g10')),
            'g11' => array_sum(array_column($quarters, 'g11')),
            'w1' => array_sum(array_column($quarters, 'w1')),
            'w2' => array_sum(array_column($quarters, 'w2')),
            'gst_purchases' => array_sum(array_column($quarters, 'gst_purchases')),
        ];
        $totals['net'] = $totals['gst_sales'] - $totals['gst_purchases'];

        return [
            'fyStart' => $fyStart,
            'fyEnd' => $fyEndDate,
            'quarters' => $quarters,
            'totals' => $totals,
        ];
    }

    /**
     * Export BAS to PDF
     */
    public function exportBasPdf(Request $request)
    {
        $currentFyEnd = now()->month >= 7 ? now()->year + 1 : now()->year;
        $fyEnd = (int) $request->get('fy', $currentFyEnd);
        $statement = $this->buildBasStatement($fyEnd);

        $pdf = Pdf::loadView('taxation.pdf.bas', [
            'fyEnd' => $fyEnd,
            'statement' => $statement,
            'priorYear' => $this->priorYearUnsettled($fyEnd),
        ]);

        return $pdf->download("BAS_FY{$fyEnd}.pdf");
    }

    /**
     * Export BAS to Excel
     */
    public function exportBasExcel(Request $request)
    {
        $currentFyEnd = now()->month >= 7 ? now()->year + 1 : now()->year;
        $fyEnd = (int) $request->get('fy', $currentFyEnd);
        $statement = $this->buildBasStatement($fyEnd);

        $export = new BasExport($fyEnd, $statement, $this->priorYearUnsettled($fyEnd));

        return Excel::download($export, "BAS_FY{$fyEnd}.xlsx");
    }

    /**
     * Annual Company Tax Report — screen entry point (ATO Company Tax
     * Return, income year ended 30 June).
     */
    public function companyTax(Request $request)
    {
        // FY named by its June year-end, derived from the entity's
        // year_start so it cannot drift from the ledger's FY boundaries.
        $currentFyEnd = ReportingPeriod::year(now(), $this->ifrsEntity()) + 1;
        $fyEnd = (int) $request->get('fy', $currentFyEnd);

        $statement = $this->buildCompanyTaxStatement($fyEnd);
        $availableFys = range($currentFyEnd, $currentFyEnd - 5);

        return view('taxation.company-tax', compact(
            'fyEnd', 'currentFyEnd', 'availableFys', 'statement'
        ));
    }

    /**
     * Export Company Tax Report to PDF
     */
    public function exportCompanyTaxPdf(Request $request)
    {
        $currentFyEnd = now()->month >= 7 ? now()->year + 1 : now()->year;
        $fyEnd = (int) $request->get('fy', $currentFyEnd);
        $statement = $this->buildCompanyTaxStatement($fyEnd);

        $pdf = Pdf::loadView('taxation.pdf.company-tax', [
            'fyEnd' => $fyEnd,
            'statement' => $statement,
        ]);

        return $pdf->download("CompanyTax_FY{$fyEnd}.pdf");
    }

    /**
     * Export Company Tax Report to Excel
     */
    public function exportCompanyTaxExcel(Request $request)
    {
        $currentFyEnd = now()->month >= 7 ? now()->year + 1 : now()->year;
        $fyEnd = (int) $request->get('fy', $currentFyEnd);
        $statement = $this->buildCompanyTaxStatement($fyEnd);

        $export = new CompanyTaxExport($fyEnd, $statement);

        return Excel::download($export, "CompanyTax_FY{$fyEnd}.xlsx");
    }

    /**
     * Export Company Tax Report to CSV (spec §6.1 audit-trail columns)
     */
    public function exportCompanyTaxCsv(Request $request)
    {
        $currentFyEnd = now()->month >= 7 ? now()->year + 1 : now()->year;
        $fyEnd = (int) $request->get('fy', $currentFyEnd);
        $statement = $this->buildCompanyTaxStatement($fyEnd);

        $export = new CompanyTaxExport($fyEnd, $statement);

        return Excel::download($export, "CompanyTax_FY{$fyEnd}.csv", \Maatwebsite\Excel\Excel::CSV);
    }

    /**
     * Company tax report figures for the ATO Company Tax Return (income
     * year 1 July – 30 June), per ATO_tax_report_spec.md. Label letters
     * and names come from the Taxation module's ato_tax_report config and follow the
     * Company tax return 2026 (NAT 0656).
     *
     * Item 6/7 amounts are cash-basis and GST-exclusive by construction:
     * only ledger rows whose parent transaction's main account is a BANK
     * account are included (client receipts and supplier payments are
     * the sole posters to revenue/expense in this system, so non-cash
     * journals are excluded), and the GST back-out legs post to the same
     * revenue/expense account, leaving per-account net movement already
     * net of GST — the form's "exclude input tax credits" rule.
     *
     * Raw DB queries are used because the package's EntityScope cannot
     * resolve background contexts; entity_id is therefore filtered
     * explicitly and soft deletes (deleted_at) honoured manually.
     */
    protected function buildCompanyTaxStatement(int $fyEnd): array
    {
        // FY boundaries from the entity's year_start ($fyEnd is the FY's
        // ending calendar year; the FY label is one less).
        $entity = $this->ifrsEntity();
        ['start' => $fyStart, 'end' => $fyEndDate] = (new FiscalYearService)->bounds($entity, $fyEnd - 1);
        $fyStart = $fyStart->startOfDay();
        $fyEndDate = $fyEndDate->copy()->endOfDay();
        $this->getReportingPeriod($fyEndDate);

        $config = config('ato_tax_report');
        $flags = $config['account_flags'];
        $warnings = [];

        $revenueTypes = [Account::OPERATING_REVENUE, Account::NON_OPERATING_REVENUE];
        $expenseTypes = [
            Account::OPERATING_EXPENSE, Account::DIRECT_EXPENSE,
            Account::OVERHEAD_EXPENSE, Account::OTHER_EXPENSE,
        ];
        $movementTypes = [...$revenueTypes, ...$expenseTypes, Account::NON_CURRENT_ASSET, Account::EQUITY];

        // Per-account net movement for the year through bank-settled
        // transactions (the transaction's main account must be a BANK).
        // Year-end closing entries are never trading activity — excluded
        // by reference prefix (belt-and-braces: their main account is
        // Retained Earnings, so the bank filter already drops them).
        $accountMovements = DB::table('ifrs_ledgers as l')
            ->join('ifrs_transactions as t', 't.id', '=', 'l.transaction_id')
            ->join('ifrs_accounts as a', 'a.id', '=', 'l.post_account')
            ->join('ifrs_accounts as bank', 'bank.id', '=', 't.account_id')
            ->where('a.entity_id', $entity->id)
            ->whereIn('a.account_type', $movementTypes)
            ->where('bank.account_type', Account::BANK)
            ->whereBetween('l.posting_date', [$fyStart, $fyEndDate])
            ->whereNull('l.deleted_at')
            ->whereNull('t.deleted_at')
            ->where(fn ($q) => $q->whereNull('t.reference')->orWhereNot('t.reference', 'like', FiscalYearClose::CLOSING_REFERENCE_PREFIX.'%'))
            ->groupBy('a.id', 'a.code', 'a.name', 'a.account_type')
            ->orderBy('a.code')
            ->selectRaw("a.id, a.code, a.name, a.account_type,
                SUM(CASE WHEN l.entry_type = 'D' THEN l.amount ELSE 0 END) as debits,
                SUM(CASE WHEN l.entry_type = 'C' THEN l.amount ELSE 0 END) as credits")
            ->get();

        // Audit trail: transaction/line-item ids per account. Fetched as
        // distinct rows instead of GROUP_CONCAT so MySQL's
        // group_concat_max_len cannot silently truncate the id list.
        $auditByAccount = [];
        DB::table('ifrs_ledgers as l')
            ->join('ifrs_transactions as t', 't.id', '=', 'l.transaction_id')
            ->join('ifrs_accounts as bank', 'bank.id', '=', 't.account_id')
            ->join('ifrs_accounts as a', 'a.id', '=', 'l.post_account')
            ->where('a.entity_id', $entity->id)
            ->whereIn('a.account_type', $movementTypes)
            ->where('bank.account_type', Account::BANK)
            ->whereBetween('l.posting_date', [$fyStart, $fyEndDate])
            ->whereNull('l.deleted_at')
            ->whereNull('t.deleted_at')
            ->where(fn ($q) => $q->whereNull('t.reference')->orWhereNot('t.reference', 'like', FiscalYearClose::CLOSING_REFERENCE_PREFIX.'%'))
            ->distinct()
            ->get(['a.id as account_id', 'l.transaction_id', 'l.line_item_id'])
            ->each(function ($link) use (&$auditByAccount) {
                $auditByAccount[$link->account_id]['txn'][] = $link->transaction_id;
                if ($link->line_item_id) {
                    $auditByAccount[$link->account_id]['line'][] = $link->line_item_id;
                }
            });

        // Bank flows for the cash cross-checks (V05/V06).
        $bankFlows = DB::table('ifrs_ledgers as l')
            ->join('ifrs_accounts as a', 'a.id', '=', 'l.post_account')
            ->where('a.entity_id', $entity->id)
            ->where('a.account_type', Account::BANK)
            ->whereBetween('l.posting_date', [$fyStart, $fyEndDate])
            ->whereNull('l.deleted_at')
            ->selectRaw("SUM(CASE WHEN l.entry_type = 'D' THEN l.amount ELSE 0 END) as inflows,
                SUM(CASE WHEN l.entry_type = 'C' THEN l.amount ELSE 0 END) as outflows")
            ->first();

        // GST collected/paid from the Vat account ledger legs, netted
        // per account role (see ledgerGst()). Shared with the GST/BAS
        // reports so every GST figure uses the same cash basis.
        $gst = $this->ledgerGst($entity, $fyStart, $fyEndDate);

        // Label rows initialised from config (form order preserved).
        $makeRows = function (array $defs): array {
            $rows = [];
            foreach ($defs as $label => $def) {
                $rows[$label] = [
                    'item' => '6',
                    'label' => $label,
                    'name' => $def['name'],
                    'note' => $def['note'] ?? null,
                    'accounts' => [],
                    'amount' => 0.0,
                    'total' => (bool) ($def['total'] ?? false),
                    'sourced' => ! empty($def['accounts']),
                    'transaction_ids' => [],
                    'line_item_ids' => [],
                ];
            }

            return $rows;
        };
        $incomeRows = $makeRows($config['income_labels']);
        $expenseRows = $makeRows($config['expense_labels']);

        $accountMap = function (array $defs): array {
            $map = [];
            foreach ($defs as $label => $def) {
                foreach ($def['accounts'] ?? [] as $code) {
                    $map[(int) $code] = $label;
                }
            }

            return $map;
        };
        $incomeAccountMap = $accountMap($config['income_labels']);
        $expenseAccountMap = $accountMap($config['expense_labels']);

        $assignAccount = function (array &$rows, string $label, object $m, float $net, array $audit): void {
            $rows[$label]['accounts'][] = [
                'code' => $m->code, 'name' => $m->name, 'amount' => round($net),
            ];
            $rows[$label]['amount'] += $net;
            $rows[$label]['sourced'] = true;
            $rows[$label]['transaction_ids'] = array_merge($rows[$label]['transaction_ids'], $audit['txn'] ?? []);
            $rows[$label]['line_item_ids'] = array_merge($rows[$label]['line_item_ids'], $audit['line'] ?? []);
        };

        $nonDeductible = 0.0;
        $exemptIncome = 0.0;
        $otherNonAssessable = 0.0;
        $capitalAccounts = [];
        $dividendsPaid = 0.0;
        $salaryTotal = 0.0;
        $salaryAccounts = array_map('intval', $config['salary_expense_accounts']);

        foreach ($accountMovements as $m) {
            $isRevenue = in_array($m->account_type, $revenueTypes);
            $net = $isRevenue
                ? (float) $m->credits - (float) $m->debits
                : (float) $m->debits - (float) $m->credits;
            $audit = $auditByAccount[$m->id] ?? [];
            $flag = $flags[(int) $m->code] ?? null;

            if ($flag === 'excluded') {
                continue;
            }

            if ($m->account_type === Account::NON_CURRENT_ASSET) {
                // Capital purchases: reference data for Item 10 (SBE
                // simplified depreciation), never an Item 6 deduction.
                $capitalAccounts[] = ['code' => $m->code, 'name' => $m->name, 'amount' => round($net)];

                continue;
            }

            if ($m->account_type === Account::EQUITY) {
                if ((int) $m->code === (int) $config['dividends_paid_account']) {
                    $dividendsPaid = round($net);
                }

                // Other equity movements (share capital, injections) do
                // not appear on the return's sourced labels; they are
                // covered by the V05 bank-flow explanation note.
                continue;
            }

            if ($isRevenue) {
                $mapped = $incomeAccountMap[(int) $m->code] ?? null;
                $label = $mapped ?? ($config['fallback'][$m->account_type] ?? 'R');
                if ($mapped === null) {
                    $warnings[] = "Income account {$m->code} {$m->name} is not mapped in the Taxation module's ato_tax_report config — reported at Item 6 label {$label}.";
                }
                $assignAccount($incomeRows, $label, $m, $net, $audit);
                if ($flag === 'non_assessable_exempt') {
                    $exemptIncome += $net;
                } elseif ($flag === 'non_assessable_other') {
                    $otherNonAssessable += $net;
                }
            } else {
                $mapped = $expenseAccountMap[(int) $m->code] ?? null;
                $label = $mapped ?? $config['fallback']['expense'];
                if ($mapped === null) {
                    $warnings[] = "Expense account {$m->code} {$m->name} is not mapped in the Taxation module's ato_tax_report config — reported at Item 6 label {$label}.";
                }
                $assignAccount($expenseRows, $label, $m, $net, $audit);
                if ($flag === 'non_deductible') {
                    $nonDeductible += $net;
                }
                if (in_array((int) $m->code, $salaryAccounts)) {
                    $salaryTotal += $net;
                }
            }
        }

        // Round each label to whole dollars, then derive totals from the
        // rounded labels so V01–V04 hold exactly (spec V08).
        foreach ($incomeRows as $label => &$row) {
            if (! $row['total']) {
                $row['amount'] = round($row['amount']);
                $row['transaction_ids'] = implode(',', array_unique($row['transaction_ids']));
                $row['line_item_ids'] = implode(',', array_unique($row['line_item_ids']));
            }
        }
        unset($row);
        foreach ($expenseRows as $label => &$row) {
            if (! $row['total']) {
                $row['amount'] = round($row['amount']);
                $row['transaction_ids'] = implode(',', array_unique($row['transaction_ids']));
                $row['line_item_ids'] = implode(',', array_unique($row['line_item_ids']));
            }
        }
        unset($row);

        $sumNonTotals = fn (array $rows) => round(array_sum(array_map(
            fn ($r) => $r['total'] ? 0 : $r['amount'], array_values($rows)
        )));

        $totalIncome = $sumNonTotals($incomeRows);
        $totalExpenses = $sumNonTotals($expenseRows);
        $incomeRows['S']['amount'] = $totalIncome;
        $expenseRows['Q']['amount'] = $totalExpenses;

        $profitOrLoss = $totalIncome - $totalExpenses; // 6-T
        $nonDeductible = round($nonDeductible);
        $exemptIncome = round($exemptIncome);
        $otherNonAssessable = round($otherNonAssessable);
        $taxableIncome = $profitOrLoss + $nonDeductible - $exemptIncome - $otherNonAssessable; // 7-T
        $capitalTotal = array_sum(array_column($capitalAccounts, 'amount'));

        $gstCollected = round((float) $gst->collected);
        $gstPaid = round((float) $gst->paid);
        $bankInflows = round((float) $bankFlows->inflows);
        $bankOutflows = round((float) $bankFlows->outflows);

        // Item 8 as-at balances at 30 June: the opening snapshot in force
        // plus ledger movement after it — the same basis as the trial
        // balance.
        $asAtBalance = function (array $types) use ($entity, $fyEndDate): float {
            $total = 0.0;
            foreach (Account::where('entity_id', $entity->id)->whereIn('account_type', $types)->get() as $account) {
                $total += OpeningBalances::balanceAt($account, $entity, $fyEndDate);
            }

            return $total;
        };
        $tradeDebtors = round($asAtBalance([Account::RECEIVABLE]));
        $currentAssets = round($asAtBalance([
            Account::BANK, Account::RECEIVABLE, Account::CURRENT_ASSET, Account::INVENTORY,
        ]));
        $totalAssets = round($asAtBalance([
            Account::BANK, Account::RECEIVABLE, Account::CURRENT_ASSET, Account::INVENTORY,
            Account::NON_CURRENT_ASSET, Account::CONTRA_ASSET,
        ]));
        $tradeCreditors = round(abs($asAtBalance([Account::PAYABLE])));
        $currentLiabilities = round(abs($asAtBalance([Account::PAYABLE, Account::CURRENT_LIABILITY])));
        $totalLiabilities = round(abs($asAtBalance([
            Account::PAYABLE, Account::CURRENT_LIABILITY, Account::NON_CURRENT_LIABILITY,
        ])));

        // Non-cash P&L ledger rows excluded by the bank filter (V07).
        // Closing entries would otherwise dominate this count: they are
        // non-bank transactions hitting P&L accounts by design.
        $nonCashRows = DB::table('ifrs_ledgers as l')
            ->join('ifrs_transactions as t', 't.id', '=', 'l.transaction_id')
            ->join('ifrs_accounts as a', 'a.id', '=', 'l.post_account')
            ->join('ifrs_accounts as main', 'main.id', '=', 't.account_id')
            ->where('a.entity_id', $entity->id)
            ->whereIn('a.account_type', [...$revenueTypes, ...$expenseTypes])
            ->where('main.account_type', '!=', Account::BANK)
            ->whereBetween('l.posting_date', [$fyStart, $fyEndDate])
            ->whereNull('l.deleted_at')
            ->whereNull('t.deleted_at')
            ->where(fn ($q) => $q->whereNull('t.reference')->orWhereNot('t.reference', 'like', FiscalYearClose::CLOSING_REFERENCE_PREFIX.'%'))
            ->count();

        // Data completeness: best-effort posting means payments can exist
        // without ever reaching the ledger.
        $unpostedPayments = Payment::whereNull('ifrs_receipt_id')
            ->where('status', '!=', Payment::STATUS_VOID)->count();
        $unpostedBillPayments = BillPayment::whereNull('ifrs_payment_id')
            ->where('status', '!=', BillPayment::STATUS_VOID)->count();
        if ($unpostedPayments || $unpostedBillPayments) {
            $warnings[] = sprintf(
                '%d client payment(s) and %d bill payment(s) are not posted to the IFRS ledger and are excluded from this report (employee expenses pending approval included).',
                $unpostedPayments,
                $unpostedBillPayments
            );
        }

        // Spec §7 validation rules.
        $validations = [];
        $addValidation = function (string $code, string $description, bool $pass, string $detail) use (&$validations): void {
            $validations[] = [
                'code' => $code,
                'description' => $description,
                'status' => $pass ? 'PASS' : 'FAIL',
                'detail' => $detail,
            ];
        };

        $negativeLabels = [];
        foreach ([...$incomeRows, ...$expenseRows] as $row) {
            if (! $row['total'] && $row['amount'] < 0) {
                $negativeLabels[] = "6-{$row['label']} {$row['name']}";
            }
        }

        // The Calculation statement estimate follows the company profile's
        // tax rate classification (base rate entity vs other company),
        // falling back to the report config when unclassified.
        $taxRate = CompanyProfile::effectiveTaxRate($entity?->id)
            ?: (float) $config['company_tax_rate'];
        $estimatedTax = max(0, $taxableIncome) * $taxRate / 100;

        $addValidation('V01', 'Total income label equals sum of income field amounts', true,
            "6-S {$totalIncome} is the sum of the rounded income labels.");
        $addValidation('V02', 'Total expenses label equals sum of expense field amounts', true,
            "6-Q {$totalExpenses} is the sum of the rounded expense labels.");
        $addValidation('V03', 'Net profit or loss equals total income minus total expenses', true,
            "6-T {$profitOrLoss} = 6-S {$totalIncome} − 6-Q {$totalExpenses}.");
        $addValidation('V04', 'Taxable income equals net profit plus add-backs less subtractions', true,
            "7-T {$taxableIncome} = 6-T {$profitOrLoss} + 7-W {$nonDeductible} − 7-V {$exemptIncome} − 7-Q {$otherNonAssessable}.");

        $inflowGap = $bankInflows - ($totalIncome + $gstCollected + $exemptIncome + $otherNonAssessable);
        $addValidation('V05', 'Bank inflows reconcile to income plus GST collected plus non-assessable receipts',
            abs($inflowGap) <= 1,
            "Bank inflows {$bankInflows} vs expected ".($bankInflows - $inflowGap)
            .'. Differences are loan proceeds, capital injections or inter-account transfers and must be explained.');

        $outflowGap = $bankOutflows - ($totalExpenses + $gstPaid + $capitalTotal);
        $addValidation('V06', 'Bank outflows reconcile to expenses plus GST paid plus capital purchases',
            abs($outflowGap) <= 1,
            "Bank outflows {$bankOutflows} vs expected ".($bankOutflows - $outflowGap)
            .'. Non-deductible expenses are already inside 6-Q (added back at 7-W).');

        $addValidation('V07', 'No non-cash transaction included', true,
            "{$nonCashRows} non-bank P&L ledger rows excluded (depreciation, revaluations, forex).");
        $addValidation('V08', 'All amounts rounded to whole dollars', true,
            'Label amounts are rounded to the nearest dollar; totals derive from rounded labels.');
        $addValidation('V09', 'No negative amounts except loss fields', empty($negativeLabels),
            empty($negativeLabels)
                ? 'All label amounts are zero or positive.'
                : 'Negative labels: '.implode(', ', $negativeLabels).' (net refunds) — review before lodging.');
        $addValidation('V10', 'Every amount traces to IFRS transactions or a declared nil label', true,
            'Non-zero labels carry source transaction ids in the CSV export; nil labels are declared in config.');
        $addValidation('V11', 'Amounts come from bank-settled transactions', true,
            'Ledger rows are restricted to transactions whose main account is a BANK account.');
        $addValidation('V12', 'Expense amounts posted to expense accounts', true,
            'Item 6 expense labels only aggregate OPERATING/DIRECT/OVERHEAD/OTHER expense accounts.');
        $hasVats = DB::table('ifrs_vats')
            ->where('entity_id', $entity->id)
            ->whereNotNull('account_id')
            ->exists();
        $addValidation('V13', 'GST excluded per registration status', $hasVats,
            $hasVats
                ? "GST collected {$gstCollected} / paid {$gstPaid} excluded from income and expense labels."
                : 'No Vat configured for the entity — GST treatment unverifiable.');

        $reconciliation = [
            ['label' => '6-T', 'name' => 'Total profit or loss (cash basis)', 'amount' => $profitOrLoss, 'note' => null, 'total' => false],
            ['label' => '7-W', 'name' => 'Add back: Non-deductible expenses', 'amount' => $nonDeductible,
                'note' => 'Meals & entertainment, income tax and franking deficit tax paid (flagged in config)', 'total' => false],
            ['label' => '7-V', 'name' => 'Less: Exempt income', 'amount' => $exemptIncome, 'note' => null, 'total' => false],
            ['label' => '7-Q', 'name' => 'Less: Other income not included in assessable income', 'amount' => $otherNonAssessable, 'note' => null, 'total' => false],
            ['label' => '7-F', 'name' => 'Less: Decline in value of depreciating assets', 'amount' => null,
                'note' => 'Left blank — SBE claims simplified depreciation at Item 10', 'total' => false],
            ['label' => '7-R', 'name' => 'Less: Tax losses deducted', 'amount' => 0,
                'note' => 'Prior-year losses are not tracked by the system', 'total' => false],
            ['label' => '7-T', 'name' => 'Taxable or net income or loss', 'amount' => $taxableIncome, 'note' => null, 'total' => true],
        ];

        // Item 8 J/K: the franked/unfranked split of dividends paid, from
        // the runs settled in the year (the ledger's account 3400 movement
        // is the total; the declarations' franking percentages split it).
        // Soft dependency: the split exists only with the Shares module.
        $dividendRuns = class_exists(DividendDeclaration::class)
            ? DividendDeclaration::query()
                ->where('entity_id', $entity->id)
                ->where('status', DividendDeclaration::STATUS_COMPLETED)
                ->whereBetween('payment_date', [$fyStart->toDateString(), $fyEndDate->toDateString()])
                ->get()
            : collect();
        $frankedDividends = round($dividendRuns->sum(fn ($d) => $d->frankedCashPortion()));
        $unfrankedDividends = round($dividendRuns->sum(fn ($d) => $d->unfrankedCashPortion()));
        if (($frankedDividends + $unfrankedDividends) > 0 && abs($frankedDividends + $unfrankedDividends - $dividendsPaid) > 1) {
            $warnings[] = sprintf(
                'Dividends settled per the dividend module (%d) differ from ledger account %d movement (%d) — manual journals to that account are not reflected in the 8-J/8-K split.',
                $frankedDividends + $unfrankedDividends,
                $config['dividends_paid_account'],
                $dividendsPaid,
            );
        }
        // Soft dependency: the franking balances exist only with the
        // Shares module enabled; without it the tax report shows zero.
        $frankingOpening = class_exists(FrankingService::class)
            ? round(FrankingService::openingBalance($fyEnd - 1, $entity->id))
            : 0.0;
        $frankingClosing = class_exists(FrankingService::class)
            ? round(FrankingService::closingBalance($fyEnd - 1, $entity->id))
            : 0.0;

        // Supplementary equity reconciliation (beyond the ATO labels):
        // the ledger's equity story for the year. EQUITY accounts are
        // credit-normal, so balanceAt()'s debit-positive figures are
        // negated for display; the current-year result matches the
        // balance sheet's on-the-fly equity figure.
        $equityAccounts = Account::where('entity_id', $entity->id)
            ->where('account_type', Account::EQUITY)
            ->get();
        $equityAt = fn (Carbon $asOf): float => round(-$equityAccounts->sum(
            fn ($account) => OpeningBalances::balanceAt($account, $entity, $asOf)
        ), 2);

        $equityBroughtForward = $equityAt($fyStart->copy()->subSecond());
        $equityResult = round((new FiscalYearService)->netProfitExcludingClosures($entity, $fyStart, $fyEndDate), 2);
        $dividendsPaid = -round($frankedDividends + $unfrankedDividends);
        $equityClosing = $equityAt($fyEndDate);

        // Residual: equity-account movements none of the rows above cover
        // — share issues, capital injections and the like — so the
        // reconciliation identity holds exactly whatever hits equity.
        $otherEquity = round($equityClosing - $equityBroughtForward - $equityResult - $dividendsPaid, 2);

        $equityReconciliation = [
            ['label' => 'EQ-1', 'name' => 'Equity brought forward (opening snapshot at FY start)',
                'amount' => $equityBroughtForward, 'note' => null],
            ['label' => 'EQ-2', 'name' => 'Result for the year (net profit, closing entries excluded)',
                'amount' => $equityResult, 'note' => null],
            ['label' => 'EQ-3', 'name' => 'Dividends paid (labels 8-J/8-K basis)',
                'amount' => $dividendsPaid, 'note' => null],
            ['label' => 'EQ-4', 'name' => 'Other equity movements',
                'amount' => $otherEquity, 'note' => 'Share issues, capital injections and other equity movements'],
            ['label' => 'EQ-5', 'name' => 'Equity at FY end (opening snapshot at FY end)',
                'amount' => $equityClosing, 'note' => 'EQ-1 + EQ-2 + EQ-3 + EQ-4'],
        ];

        $financialInfo = [
            ['label' => 'C', 'name' => 'Trade debtors', 'amount' => $tradeDebtors, 'note' => null],
            ['label' => 'D', 'name' => 'All current assets', 'amount' => $currentAssets, 'note' => null],
            ['label' => 'E', 'name' => 'Total assets', 'amount' => $totalAssets, 'note' => null],
            ['label' => 'F', 'name' => 'Trade creditors', 'amount' => $tradeCreditors, 'note' => null],
            ['label' => 'G', 'name' => 'All current liabilities', 'amount' => $currentLiabilities, 'note' => null],
            ['label' => 'H', 'name' => 'Total liabilities', 'amount' => $totalLiabilities, 'note' => null],
            ['label' => 'D', 'name' => 'Total salary and wage expenses', 'amount' => round($salaryTotal),
                'note' => 'Information label — accounts '.implode(', ', $salaryAccounts).' (no payroll ledger kept)'],
            ['label' => 'J', 'name' => 'Franked dividends paid', 'amount' => $frankedDividends,
                'note' => 'Franked portion of the dividend runs settled in the year'],
            ['label' => 'K', 'name' => 'Unfranked dividends paid', 'amount' => $unfrankedDividends,
                'note' => 'Unfranked portion of the dividend runs settled in the year'],
            ['label' => 'P', 'name' => 'Franking account balance — opening', 'amount' => $frankingOpening,
                'note' => 'Per the franking account ledger'],
            ['label' => 'M', 'name' => 'Franking account balance — closing', 'amount' => $frankingClosing,
                'note' => 'Per the franking account ledger'],
        ];

        return [
            'fyStart' => $fyStart,
            'fyEnd' => $fyEndDate,
            'fyEndYear' => $fyEnd,
            'entity' => [
                'name' => $entity?->name ?? '',
                // Profile first, legacy env config as fallback. Kept as
                // bare digits — the CSV/Excel export shares this array;
                // the screens format for display.
                'abn' => CompanyProfile::effectiveAbn($entity?->id),
                'tfn' => CompanyProfile::effectiveTfn($entity?->id),
            ],
            'income' => $incomeRows,
            'expenses' => $expenseRows,
            'totalIncome' => $totalIncome,
            'totalExpenses' => $totalExpenses,
            'profitOrLoss' => $profitOrLoss,
            'reconciliation' => $reconciliation,
            'taxableIncome' => $taxableIncome,
            'financialInfo' => $financialInfo,
            'equityReconciliation' => $equityReconciliation,
            'capitalPurchases' => ['accounts' => $capitalAccounts, 'total' => $capitalTotal],
            'gst' => ['collected' => $gstCollected, 'paid' => $gstPaid],
            'bank' => ['inflows' => $bankInflows, 'outflows' => $bankOutflows],
            'taxRate' => $taxRate,
            'estimatedTax' => round($estimatedTax),
            'validations' => $validations,
            'warnings' => $warnings,
        ];
    }
}
