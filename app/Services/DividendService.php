<?php

namespace App\Services;

use App\Mail\DividendStatementMail;
use App\Models\CompanyProfile;
use App\Models\DividendDeclaration;
use App\Models\DividendDistribution;
use IFRS\Models\Account;
use IFRS\Models\Entity;
use IFRS\Models\LineItem;
use IFRS\Transactions\JournalEntry;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Dividend declaration lifecycle: eligibility calculation, franking credit
 * attachment, approval and the two-stage GL posting.
 *
 *   Approval:   Dr Dividends Paid (3400) / Cr Dividends Payable (2260)
 *   Paid run:   Dr Dividends Payable (2260) / Cr Bank
 *
 * Franking credits are deliberately absent from both journals — they are a
 * notional franking-account movement, recorded as an FD entry when the run
 * is marked paid. The run itself is executed manually from the on-screen
 * payment schedule (no in-system payments); recording it triggers the
 * per-shareholder statement emails.
 */
class DividendService
{
    /**
     * Franking credit attached to a cash dividend per the spec:
     * cash x franking% x (rate / (100 - rate)), rounded to cents.
     * $70 fully franked at a 30% rate attaches $30.00.
     */
    public static function calculateFrankingCredit(float $cashDividend, float $frankingPercentage, float $corporateTaxRate): float
    {
        if ($cashDividend < 0) {
            throw new \InvalidArgumentException('Cash dividend cannot be negative.');
        }
        if ($frankingPercentage < 0 || $frankingPercentage > 100) {
            throw new \InvalidArgumentException('Franking percentage must be between 0 and 100.');
        }
        if ($corporateTaxRate <= 0 || $corporateTaxRate >= 100) {
            throw new \InvalidArgumentException('Invalid corporate tax rate.');
        }

        $frankedPortion = $cashDividend * ($frankingPercentage / 100);
        $grossUpFactor = $corporateTaxRate / (100 - $corporateTaxRate);

        return round($frankedPortion * $grossUpFactor, 2);
    }

    /**
     * (Re)generate the distribution lines for a draft declaration from the
     * shareholdings ledger at the books-close date. Lines are rounded per
     * shareholder so the declaration totals equal the sum of the lines
     * exactly. Returns the number of lines created.
     */
    public static function generateDistributions(DividendDeclaration $declaration): int
    {
        if ($declaration->status !== DividendDeclaration::STATUS_DRAFT) {
            throw new \InvalidArgumentException('Only draft declarations can be recalculated.');
        }

        $class = $declaration->shareClass;
        if (!$class?->dividend_rights) {
            throw new \InvalidArgumentException('The share class does not carry dividend rights.');
        }

        $profile = CompanyProfile::where('entity_id', $declaration->entity_id)->first();
        if (!$profile) {
            throw new \InvalidArgumentException('No company profile exists for this entity — maintain shareholders first.');
        }

        // Classes without franking entitlement attach no credits even if
        // the declaration asks for them.
        $frankingPct = $class->franking_entitlement ? (float) $declaration->franking_percentage : 0.0;

        $eligible = \App\Models\Shareholding::query()
            ->join('company_shareholders', 'company_shareholders.id', '=', 'shareholdings.company_shareholder_id')
            ->where('company_shareholders.company_profile_id', $profile->id)
            ->where('company_shareholders.status', \App\Models\CompanyShareholder::STATUS_ACTIVE)
            ->where('shareholdings.status', \App\Models\Shareholding::STATUS_ACTIVE)
            ->where('shareholdings.share_class_id', $declaration->share_class_id)
            ->whereDate('shareholdings.transaction_date', '<=', $declaration->books_close_date->toDateString())
            ->groupBy('shareholdings.company_shareholder_id')
            ->selectRaw('shareholdings.company_shareholder_id, SUM(shareholdings.quantity) as shares')
            ->havingRaw('SUM(shareholdings.quantity) > 0')
            ->get();

        return \Illuminate\Support\Facades\DB::transaction(function () use ($declaration, $eligible, $frankingPct) {
            $declaration->distributions()->delete();

            $totalShares = 0;
            $totalCash = 0.0;
            $totalFranking = 0.0;

            foreach ($eligible as $row) {
                $shareholder = \App\Models\CompanyShareholder::find($row->company_shareholder_id);
                $shares = (int) $row->shares;

                $cash = round($shares * (float) $declaration->amount_per_share, 2);
                $franking = self::calculateFrankingCredit($cash, $frankingPct, (float) $declaration->franking_credit_rate);
                $grossedUp = round($cash + $franking, 2);

                $declaration->distributions()->create([
                    'company_shareholder_id' => $shareholder->id,
                    'shareholder_name' => $shareholder->name,
                    'share_class_id' => $declaration->share_class_id,
                    'shares_eligible' => $shares,
                    'cash_dividend' => $cash,
                    'franking_credit' => $franking,
                    'grossed_up_dividend' => $grossedUp,
                    'withholding_tax' => 0, // non-resident withholding is out of scope (Phase 4)
                    'net_payment' => $cash,
                    'payment_reference' => self::paymentReference($declaration, $shareholder->id),
                    'status' => DividendDistribution::STATUS_PENDING,
                    'created_by' => auth()->id(),
                ]);

                $totalShares += $shares;
                $totalCash = round($totalCash + $cash, 2);
                $totalFranking = round($totalFranking + $franking, 2);
            }

            $declaration->forceFill([
                'total_shares_eligible' => $totalShares,
                'total_cash_dividend' => $totalCash,
                'total_franking_credit' => $totalFranking,
                'total_grossed_up' => round($totalCash + $totalFranking, 2),
            ])->save();

            return $eligible->count();
        });
    }

    /**
     * Approve a draft declaration: verify sufficient franking credits, post
     * the declaration journal and lock the calculation.
     */
    public static function approve(DividendDeclaration $declaration): void
    {
        if ($declaration->status !== DividendDeclaration::STATUS_DRAFT) {
            throw new \InvalidArgumentException('Only draft declarations can be approved.');
        }
        if ($declaration->distributions()->count() === 0) {
            throw new \InvalidArgumentException('Calculate the distribution lines before approving.');
        }
        if ((float) $declaration->total_cash_dividend <= 0) {
            throw new \InvalidArgumentException('The declaration has no cash dividend to post.');
        }

        $entity = IfrsPosting::resolveEntity();
        abort_unless((bool) $entity, 503, 'No IFRS entity available for dividend posting.');
        self::assertDatePostable($declaration->declaration_date, $entity, 'declaration date');

        $required = (float) $declaration->total_franking_credit;
        if ($required > 0) {
            $available = FrankingService::availableBalance(now(), $declaration->entity_id ?: $entity->id);
            if ($required > $available) {
                throw new \InvalidArgumentException(sprintf(
                    'Insufficient franking credits: this dividend attaches %s but only %s is available '
                    . '(after approved-but-unpaid declarations and estimated entries). '
                    . 'Reduce the franking percentage or record franking credits first.',
                    number_format($required, 2),
                    number_format($available, 2),
                ));
            }
        }

        \Illuminate\Support\Facades\DB::transaction(function () use ($declaration, $entity) {
            $journalEntry = self::postJournal(
                $declaration->declaration_date,
                $entity,
                mainCode: config('dividends.accounts.dividends_paid'),
                lineCode: config('dividends.accounts.dividends_payable'),
                amount: (float) $declaration->total_cash_dividend,
                narration: "Dividend declared: {$declaration->declaration_number} ({$declaration->shareClass?->code})",
                reference: $declaration->declaration_number,
            );

            $declaration->forceFill([
                'status' => DividendDeclaration::STATUS_APPROVED,
                'approved_by' => auth()->id(),
                'approved_at' => now(),
                'ifrs_declaration_transaction_id' => $journalEntry->id,
            ])->save();
        });
    }

    /**
     * Record that the manually-executed payment run has been settled:
     * post the payment journal, create the franking debit and mark every
     * distribution paid. Call sendStatements() afterwards.
     */
    public static function recordPayment(DividendDeclaration $declaration): void
    {
        if ($declaration->status !== DividendDeclaration::STATUS_APPROVED) {
            throw new \InvalidArgumentException('Only approved declarations can be recorded as paid.');
        }

        $entity = IfrsPosting::resolveEntity();
        abort_unless((bool) $entity, 503, 'No IFRS entity available for dividend posting.');
        self::assertDatePostable($declaration->payment_date, $entity, 'payment date');

        \Illuminate\Support\Facades\DB::transaction(function () use ($declaration, $entity) {
            $journalEntry = self::postJournal(
                $declaration->payment_date,
                $entity,
                mainCode: config('dividends.accounts.dividends_payable'),
                lineCode: config('dividends.accounts.bank'),
                amount: (float) $declaration->total_cash_dividend,
                narration: "Dividend payment: {$declaration->declaration_number}",
                reference: $declaration->declaration_number,
            );

            // The franking debit arises when the franked dividend is paid.
            if ((float) $declaration->total_franking_credit > 0) {
                \App\Models\FrankingAccountEntry::create([
                    'entity_id' => $declaration->entity_id ?: $entity->id,
                    'financial_year' => $declaration->financial_year,
                    'entry_date' => $declaration->payment_date->toDateString(),
                    'entry_type' => \App\Models\FrankingAccountEntry::TYPE_FRANKED_DIVIDEND_PAID,
                    'reference' => $declaration->declaration_number,
                    'description' => 'Franked dividend paid ' . $declaration->declaration_number,
                    'debit_amount' => (float) $declaration->total_franking_credit,
                    'is_estimated' => false,
                    'dividend_declaration_id' => $declaration->id,
                    'created_by' => auth()->id(),
                ]);
            }

            $declaration->distributions()
                ->where('status', DividendDistribution::STATUS_PENDING)
                ->update(['status' => DividendDistribution::STATUS_PAID, 'paid_at' => now()]);

            $declaration->forceFill([
                'status' => DividendDeclaration::STATUS_COMPLETED,
                'paid_at' => now(),
                'ifrs_payment_transaction_id' => $journalEntry->id,
            ])->save();
        });
    }

    /**
     * Cancel a draft or approved declaration. An approved declaration's
     * journal is mirrored back out (posted entries are never mutated);
     * payment journals cannot exist here — completed runs cannot cancel.
     */
    public static function cancel(DividendDeclaration $declaration): void
    {
        if (!$declaration->canTransitionTo(DividendDeclaration::STATUS_CANCELLED)) {
            throw new \InvalidArgumentException("A {$declaration->status} declaration cannot be cancelled.");
        }

        \Illuminate\Support\Facades\DB::transaction(function () use ($declaration) {
            if ($declaration->ifrs_declaration_transaction_id) {
                IfrsPosting::reverseTransaction(
                    (int) $declaration->ifrs_declaration_transaction_id,
                    "Reversal of dividend declaration: {$declaration->declaration_number} (cancelled)",
                    $declaration->declaration_number,
                    throw: true,
                );
            }

            if ($declaration->status === DividendDeclaration::STATUS_APPROVED) {
                $declaration->distributions()
                    ->where('status', DividendDistribution::STATUS_PENDING)
                    ->update(['status' => DividendDistribution::STATUS_CANCELLED]);
            } else {
                $declaration->distributions()->delete();
            }

            $declaration->forceFill(['status' => DividendDeclaration::STATUS_CANCELLED])->save();
        });
    }

    /**
     * Email dividend statements (PDF attachment) for the paid distributions
     * of a declaration. Best-effort per recipient: failures are logged and
     * counted, never thrown — re-run via the command or the Send again
     * action. Returns ['sent', 'skipped', 'failed', 'missing_email'].
     */
    public static function sendStatements(DividendDeclaration $declaration, bool $force = false, bool $dryRun = false): array
    {
        $results = ['sent' => 0, 'skipped' => 0, 'failed' => 0, 'missing_email' => 0];

        $distributions = $declaration->distributions()
            ->where('status', DividendDistribution::STATUS_PAID)
            ->when(!$force, fn ($q) => $q->where('statement_sent', false))
            ->with('shareholder')
            ->get();

        foreach ($distributions as $distribution) {
            $email = $distribution->shareholder?->email;

            if (!$email) {
                $results['missing_email']++;
                Log::warning('Dividend statement not sent — shareholder has no email', [
                    'distribution_id' => $distribution->id,
                    'declaration' => $declaration->declaration_number,
                ]);
                continue;
            }

            if ($dryRun) {
                $results['sent']++;
                continue;
            }

            try {
                Mail::to($email)->send(new DividendStatementMail($distribution));

                $distribution->forceFill([
                    'statement_sent' => true,
                    'statement_sent_at' => now(),
                ])->save();

                $results['sent']++;
            } catch (\Throwable $e) {
                $results['failed']++;
                Log::error('Failed to send dividend statement', [
                    'distribution_id' => $distribution->id,
                    'declaration' => $declaration->declaration_number,
                    'recipient' => $email,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $results;
    }

    /**
     * Bank statement reference for a distribution (fits the 20-char column).
     */
    public static function paymentReference(DividendDeclaration $declaration, int $shareholderId): string
    {
        return $declaration->declaration_number . '-' . str_pad((string) $shareholderId, 3, '0', STR_PAD_LEFT);
    }

    /**
     * Post one Dr main / Cr line journal using the shared posting recipe
     * (see Payment::postToIFRS): period ensured, 1 July midnight nudged,
     * line persisted before addLineItem, post() writes the ledger rows.
     */
    protected static function postJournal($date, Entity $entity, int $mainCode, int $lineCode, float $amount, string $narration, string $reference): JournalEntry
    {
        $mainAccount = Account::where('entity_id', $entity->id)->where('code', $mainCode)->first();
        $lineAccount = Account::where('entity_id', $entity->id)->where('code', $lineCode)->first();
        if (!$mainAccount || !$lineAccount) {
            throw new \RuntimeException("IFRS accounts not found for dividend posting (codes {$mainCode}/{$lineCode}).");
        }

        IfrsPosting::ensureReportingPeriod($date, $entity);

        $journalEntry = new JournalEntry([
            'transaction_date' => IfrsPosting::transactionDate($date, $entity),
            'account_id' => $mainAccount->id,
            'credited' => false, // main account debited; the line takes the credit
            'entity_id' => $entity->id,
            // Bank can be either leg (payment journal); without an explicit
            // currency the package defaults from the MAIN account only, so
            // a bank line item fails the single-currency check at addLineItem().
            'currency_id' => $entity->currency_id,
            'narration' => $narration,
            'reference' => $reference,
        ]);

        $line = LineItem::create([
            'account_id' => $lineAccount->id,
            'amount' => $amount,
            'quantity' => 1,
            'entity_id' => $entity->id,
        ]);
        $journalEntry->addLineItem($line);
        $journalEntry->post();

        return $journalEntry;
    }

    /**
     * Refuse posting into a closed IFRS year or a locked app period — same
     * guards PrepaymentService applies to console postings.
     */
    protected static function assertDatePostable($date, Entity $entity, string $label): void
    {
        $date = \Carbon\Carbon::parse($date);
        $locks = app(\App\Services\PeriodLockService::class);

        if ($locks->isDateLocked($date)) {
            throw new \InvalidArgumentException("The {$label} falls in a locked period ({$date->toDateString()}).");
        }

        if ($locks->isDateBlocked($date, $entity)) {
            throw new \InvalidArgumentException(
                $locks->dateBlockedMessage($date, $entity)
                ?? "The {$label} falls in a closed financial year ({$date->toDateString()})."
            );
        }
    }
}
