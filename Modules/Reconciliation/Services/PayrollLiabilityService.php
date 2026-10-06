<?php

namespace Modules\Reconciliation\Services;

use App\Services\IfrsPosting;
use App\Services\PeriodLockService;
use Carbon\Carbon;
use IFRS\Models\Account;
use IFRS\Models\Ledger;
use IFRS\Models\LineItem;
use IFRS\Models\Transaction;
use IFRS\Scopes\EntityScope;
use IFRS\Transactions\JournalEntry;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Reconciliation\Models\BankTransaction;
use Modules\Reconciliation\Models\ReconciliationHistory;

/**
 * Records the settlement — or the refund — of a payroll liability
 * from the bank feed. Settlement: the super or PAYG-withholding
 * payment that leaves the bank after a pay run posted its accruals.
 * Refund: the ATO or super-fund money coming BACK (an over-remitted
 * instalment), the mirror Dr bank / Cr payable — never Funds
 * Introduced equity, which is where the transfer card would put it.
 * The pay run's ACC/SUP journals credit the payables but never touch
 * the bank, so until the settlement or refund posts, the bank line
 * has no book movement to reconcile against — the "cannot match on
 * PAYROLL-*-SUP/-ACC" gap. Both paths post dated the line's own date
 * (period locks refuse a locked or closed date) and match the line to
 * the journal's bank leg, clearing the liability and the bank-vs-books
 * gap together.
 *
 * The payable candidates are the payroll module's configured statutory
 * accounts (PAYG withholding, super payable), resolved per entity —
 * the two liabilities whose settlement posts no bank movement at
 * process time. Wages payable is deliberately excluded: the pay run's
 * PAY journal already records the net leaving the bank. PAYG may also
 * be settled from the BAS settlement screen, which nets the whole
 * unpaid balance; this path settles exactly what one bank line paid,
 * and the BAS position is balance-based, so the two never double-clear.
 *
 * Cross-module by config only: without the Payroll module enabled
 * there are no candidates, the match screen hides the card, and the
 * service refuses. The match deliberately does NOT teach the
 * counterparty rules — a settlement journal is a one-off target.
 */
class PayrollLiabilityService
{
    /** Journal (and idempotency) reference prefix, the XFER precedent. */
    public const REFERENCE_PREFIX = 'PAYSET-';

    /** The payroll config keys whose accounts can be settled here. */
    protected const SETTLABLE_KEYS = ['payg_withholding', 'super_payable'];

    public function __construct(protected PeriodLockService $locks) {}

    /**
     * The payroll payables this entity can settle from a bank line:
     * the configured statutory liability accounts that exist on the
     * entity's chart. Empty when the Payroll module is disabled or
     * the accounts are not seeded.
     *
     * @return Collection<int, Account>
     */
    public function settlablePayables($entity): Collection
    {
        $codes = [];
        foreach (self::SETTLABLE_KEYS as $key) {
            $code = config("payroll.accounts.{$key}");
            if (is_numeric($code)) {
                $codes[] = (int) $code;
            }
        }

        if ($codes === []) {
            return collect();
        }

        return Account::withoutGlobalScope(EntityScope::class)
            ->where('entity_id', $entity->id)
            ->whereIn('code', $codes)
            ->orderBy('code')
            ->get();
    }

    /**
     * Post the settlement behind a pending bank debit line and match
     * the line to it: Dr $payableAccountId (one of settlablePayables)
     * / Cr $bankAccountId, dated the line's date. Throws
     * InvalidArgumentException with a user-ready message on any
     * refusal; nothing posts on refusal.
     */
    public function settle(BankTransaction $line, int $bankAccountId, int $payableAccountId, ?string $notes = null): BankTransaction
    {
        if ($line->type !== BankTransaction::TYPE_DEBIT) {
            throw new \InvalidArgumentException('Only a money-out line can settle a payroll liability — record money coming back from the ATO or a fund as a payroll liability refund.');
        }

        return $this->record($line, $bankAccountId, $payableAccountId, $notes, refund: false);
    }

    /**
     * Post the refund behind a pending bank credit line and match the
     * line to it — the ATO or super-fund returning an over-remitted
     * amount: Dr $bankAccountId / Cr $payableAccountId (one of
     * settlablePayables), dated the line's date. The mirror of
     * settle(); money that comes back against a payroll liability is
     * never Funds Introduced equity, where the transfer card would put
     * it. Throws InvalidArgumentException with a user-ready message on
     * any refusal; nothing posts on refusal.
     */
    public function refund(BankTransaction $line, int $bankAccountId, int $payableAccountId, ?string $notes = null): BankTransaction
    {
        if ($line->type !== BankTransaction::TYPE_CREDIT) {
            throw new \InvalidArgumentException('Only a money-in line can be a payroll liability refund — money paying a liability is a settlement.');
        }

        return $this->record($line, $bankAccountId, $payableAccountId, $notes, refund: true);
    }

    /**
     * The shared settlement/refund body: guards, the journal (Dr
     * payable / Cr bank settling, or Dr bank / Cr payable refunding),
     * the line's match to the journal's bank leg, and the history and
     * log rows. Reuse keyed on the same PAYSET-{line id} reference, so
     * re-recording after an unmatch re-matches the one true journal
     * whichever direction it was posted in.
     */
    private function record(BankTransaction $line, int $bankAccountId, int $payableAccountId, ?string $notes, bool $refund): BankTransaction
    {
        if ($line->status !== BankTransaction::STATUS_PENDING) {
            throw new \InvalidArgumentException('Only a pending bank line can record a payroll liability settlement or refund.');
        }

        $entity = IfrsPosting::resolveEntity();
        if ($entity === null) {
            throw new \InvalidArgumentException('No IFRS entity is configured.');
        }

        if ($line->currency !== $entity->currency?->currency_code) {
            throw new \InvalidArgumentException(
                "The line is in {$line->currency} but the books are kept in ".($entity->currency?->currency_code ?? '?')
                .' — payroll liability journals only post in the entity\'s currency.'
            );
        }

        $amount = round(abs((float) $line->amount), 2);
        if ($amount < 0.005) {
            throw new \InvalidArgumentException('The line has no amount to settle.');
        }

        // EntityScope off, entity filtered explicitly — the scope
        // cannot resolve an entity for every caller (the
        // TaxReportController precedent).
        $bankAccount = Account::withoutGlobalScope(EntityScope::class)
            ->where('entity_id', $entity->id)
            ->whereKey($bankAccountId)
            ->first();
        if (! $bankAccount || $bankAccount->account_type !== Account::BANK) {
            throw new \InvalidArgumentException('Pick a bank account from the chart of accounts.');
        }

        // The payable must be one of the entity's configured payroll
        // liabilities — a crafted id for an arbitrary account (a
        // revenue account, say) must never post through this path.
        $payable = $this->settlablePayables($entity)->firstWhere('id', $payableAccountId);
        if ($payable === null) {
            throw new \InvalidArgumentException('Pick a payroll liability account — PAYG withholding or super payable.');
        }

        $date = Carbon::parse($line->transaction_date)->startOfDay();
        $this->assertDatePostable($date);

        return DB::transaction(function () use ($line, $entity, $bankAccount, $payable, $amount, $date, $notes, $refund) {
            // Authoritative pending check under the line's row lock: a
            // double-submit racing past the check above must not post
            // a second journal for the same line.
            $line = BankTransaction::whereKey($line->id)->lockForUpdate()->firstOrFail();
            if ($line->status !== BankTransaction::STATUS_PENDING) {
                throw new \InvalidArgumentException('Only a pending bank line can settle a payroll liability.');
            }

            // The journal this line needs may already be posted: an
            // unmatch returned the line to pending, but the movement —
            // the real payment — never left the books. Re-match it,
            // never double-post.
            $leg = $this->existingSettlementLeg($entity, $line, $bankAccount);

            $narration = $refund
                ? "Payroll liability refund — {$payable->name} received into {$bankAccount->name}"
                : "Payroll liability payment — {$payable->name} settled from {$bankAccount->name}";

            $journal = null;
            if ($leg === null) {
                IfrsPosting::ensureReportingPeriod($date, $entity);

                // Settling: Dr payable (main) / Cr bank. Refunding: the
                // mirror — Dr bank (main) / Cr payable.
                $journal = new JournalEntry([
                    'transaction_date' => IfrsPosting::transactionDate($date, $entity),
                    'account_id' => $refund ? $bankAccount->id : $payable->id,
                    'credited' => false,
                    'entity_id' => $entity->id,
                    // Carried explicitly: a non-bank main account would
                    // not default the currency.
                    'currency_id' => $entity->currency_id,
                    'narration' => $narration,
                    'reference' => self::REFERENCE_PREFIX.$line->id,
                ]);
                $journal->addLineItem(LineItem::create([
                    'account_id' => $refund ? $payable->id : $bankAccount->id,
                    'amount' => $amount,
                    'quantity' => 1,
                    'entity_id' => $entity->id,
                ]));
                $journal->post();

                $leg = Ledger::where('transaction_id', $journal->id)
                    ->where('post_account', $bankAccount->id)
                    ->orderBy('id')
                    ->first();
                if ($leg === null) {
                    throw new \InvalidArgumentException('The settlement journal posted without a bank leg — nothing to reconcile against.');
                }
            }

            $linkNotes = ($notes ?? ($journal !== null
                ? ($refund ? 'Recorded as a payroll liability refund' : 'Recorded as a payroll liability payment')
                : 'Matched to the existing settlement journal'))
                .' on '.now()->toDateTimeString();
            $line->update([
                'status' => BankTransaction::STATUS_MATCHED,
                'matched_transaction_id' => $leg->id,
                'matched_transaction_type' => 'ledger',
                'matched_at' => now(),
                'notes' => $line->notes ? $line->notes."\n".$linkNotes : $linkNotes,
            ]);

            $details = $journal !== null
                ? $narration
                : (string) Transaction::find($leg->transaction_id)?->narration;

            ReconciliationHistory::create([
                'bank_transaction_id' => $line->id,
                'action' => ReconciliationHistory::ACTION_MANUAL_MATCH,
                'status' => ReconciliationHistory::STATUS_SUCCESS,
                'linked_transaction_id' => $leg->id,
                'linked_transaction_type' => 'ledger',
                'details' => $details,
                'notes' => $notes,
                'user_id' => auth()->id(),
            ]);

            Log::info('Payroll liability settlement recorded from reconciliation', [
                'bank_transaction_id' => $line->id,
                'transaction_id' => $leg->transaction_id,
                'reused_journal' => $journal === null,
                'bank_account' => $bankAccount->code,
                'payable' => $payable->code,
                'amount' => $amount,
                'refund' => $refund,
            ]);

            return $line;
        });
    }

    /**
     * The bank leg of this line's own earlier settlement journal
     * (reference PAYSET-{line id}), when one exists — the re-record
     * after an unmatch re-matches instead of double-posting. Refuses
     * when the journal's bank leg is not the picked account (the
     * accounts were changed; silently re-posting would double-settle).
     */
    protected function existingSettlementLeg($entity, BankTransaction $line, Account $bankAccount): ?Ledger
    {
        $ownTransactionId = Transaction::withoutGlobalScope(EntityScope::class)
            ->where('entity_id', $entity->id)
            ->where('reference', self::REFERENCE_PREFIX.$line->id)
            ->value('id');
        if ($ownTransactionId === null) {
            return null;
        }

        $leg = Ledger::where('transaction_id', $ownTransactionId)
            ->where('post_account', $bankAccount->id)
            ->orderBy('id')
            ->first();

        if ($leg === null) {
            throw new \InvalidArgumentException(
                'This line already posted a settlement journal (unmatching does not remove it) — match to its own bank account, or reverse the journal first.'
            );
        }

        return $leg;
    }

    /**
     * Refuse posting into a locked app period or a closed IFRS year —
     * the same guards the other journal-posting services apply.
     */
    protected function assertDatePostable(Carbon $date): void
    {
        if ($this->locks->isDateLocked($date)) {
            throw new \InvalidArgumentException("The settlement date falls in a locked period ({$date->toDateString()}).");
        }

        $entity = IfrsPosting::resolveEntity();
        if ($entity !== null && $this->locks->isDateBlocked($date, $entity)) {
            throw new \InvalidArgumentException(
                $this->locks->dateBlockedMessage($date, $entity)
                ?? "The settlement date falls in a closed financial year ({$date->toDateString()})."
            );
        }
    }
}
