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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Reconciliation\Models\BankTransaction;
use Modules\Reconciliation\Models\ReconciliationHistory;

/**
 * Records the bank's own charges from the feed: the interest the
 * account earns (a money-in line — Dr bank / Cr interest income) and
 * the fees the bank charges (money-out — Dr bank fees / Cr bank).
 * Both previously had no book path beyond the transfer card's Funds
 * Introduced/Withdrawn equity — the wrong class for income and
 * expense. Posting here dates the journal the line's own date
 * (period locks refuse a locked or closed date) and matches the line
 * to the journal's bank leg, so the movement reconciles and flows
 * into every cash figure: the families classify by their counterpart
 * accounts (revenue for interest, expense for fees) in the cash flow
 * card, the P&L trend and the company tax report alike.
 *
 * The accounts resolve from the module config per entity — interest
 * income is the seeded 4510; the bank-fees account is lazily created
 * on existing charts (the Funds Introduced precedent).
 */
class BankChargeService
{
    /** Journal (and idempotency) reference prefix, the XFER/PAYSET precedent. */
    public const REFERENCE_PREFIX = 'BANKCHG-';

    public function __construct(protected PeriodLockService $locks) {}

    /**
     * The interest and fee accounts, lazily created on existing
     * charts so installs predating this feature pick them up without
     * reseeding.
     *
     * @return array{interest: Account, fees: Account}
     */
    public function accounts($entity): array
    {
        $interestCode = (int) config('reconciliation.accounts.interest_income', 4510);
        $feesCode = (int) config('reconciliation.accounts.bank_fees', 5950);

        return [
            'interest' => Account::withoutGlobalScope(EntityScope::class)->firstOrCreate(
                ['entity_id' => $entity->id, 'code' => $interestCode],
                [
                    'account_type' => Account::NON_OPERATING_REVENUE,
                    'name' => 'Interest Income',
                    'currency_id' => $entity->currency_id,
                ],
            ),
            'fees' => Account::withoutGlobalScope(EntityScope::class)->firstOrCreate(
                ['entity_id' => $entity->id, 'code' => $feesCode],
                [
                    'account_type' => Account::OPERATING_EXPENSE,
                    'name' => 'Bank Fees',
                    'currency_id' => $entity->currency_id,
                ],
            ),
        ];
    }

    /**
     * Post the charge behind a pending bank line and match the line
     * to it: money-in is interest earned (Dr bank / Cr interest
     * income), money-out is fees charged (Dr bank fees / Cr bank),
     * dated the line's date. Throws InvalidArgumentException with a
     * user-ready message on any refusal; nothing posts on refusal.
     */
    public function record(BankTransaction $line, int $bankAccountId, ?string $notes = null): BankTransaction
    {
        if ($line->status !== BankTransaction::STATUS_PENDING) {
            throw new \InvalidArgumentException('Only a pending bank line can record bank interest or fees.');
        }

        $entity = IfrsPosting::resolveEntity();
        if ($entity === null) {
            throw new \InvalidArgumentException('No IFRS entity is configured.');
        }

        if ($line->currency !== $entity->currency?->currency_code) {
            throw new \InvalidArgumentException(
                "The line is in {$line->currency} but the books are kept in ".($entity->currency?->currency_code ?? '?')
                .' — bank charge journals only post in the entity\'s currency.'
            );
        }

        $amount = round(abs((float) $line->amount), 2);
        if ($amount < 0.005) {
            throw new \InvalidArgumentException('The line has no amount to record.');
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

        $isInterest = $line->type === BankTransaction::TYPE_CREDIT;

        $date = Carbon::parse($line->transaction_date)->startOfDay();
        $this->assertDatePostable($date);

        return DB::transaction(function () use ($line, $entity, $bankAccount, $amount, $date, $notes, $isInterest) {
            // Authoritative pending check under the line's row lock: a
            // double-submit racing past the check above must not post
            // a second journal for the same line.
            $line = BankTransaction::whereKey($line->id)->lockForUpdate()->firstOrFail();
            if ($line->status !== BankTransaction::STATUS_PENDING) {
                throw new \InvalidArgumentException('Only a pending bank line can record bank interest or fees.');
            }

            // The journal this line needs may already be posted: an
            // unmatch returned the line to pending, but the movement
            // never left the books. Re-match it, never double-post.
            $leg = $this->existingChargeLeg($entity, $line, $bankAccount);

            $journal = null;
            if ($leg === null) {
                IfrsPosting::ensureReportingPeriod($date, $entity);

                $accounts = $this->accounts($entity);
                $counterpart = $isInterest ? $accounts['interest'] : $accounts['fees'];

                $narration = $isInterest
                    ? "Bank interest earned — {$bankAccount->name}"
                    : "Bank fees charged — {$bankAccount->name}";

                // Interest: Dr bank (main) / Cr interest income. Fees:
                // Dr bank fees (main) / Cr bank.
                $journal = new JournalEntry([
                    'transaction_date' => IfrsPosting::transactionDate($date, $entity),
                    'account_id' => $isInterest ? $bankAccount->id : $counterpart->id,
                    'credited' => false,
                    'entity_id' => $entity->id,
                    // Carried explicitly: a non-bank main account would
                    // not default the currency.
                    'currency_id' => $entity->currency_id,
                    'narration' => $narration,
                    'reference' => self::REFERENCE_PREFIX.$line->id,
                ]);
                $journal->addLineItem(LineItem::create([
                    'account_id' => $isInterest ? $counterpart->id : $bankAccount->id,
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
                    throw new \InvalidArgumentException('The bank charge journal posted without a bank leg — nothing to reconcile against.');
                }
            }

            $linkNotes = ($notes ?? ($journal !== null
                ? ($isInterest ? 'Recorded as bank interest earned' : 'Recorded as bank fees')
                : 'Matched to the existing bank charge journal'))
                .' on '.now()->toDateTimeString();
            $line->update([
                'status' => BankTransaction::STATUS_MATCHED,
                'matched_transaction_id' => $leg->id,
                'matched_transaction_type' => 'ledger',
                'matched_at' => now(),
                'notes' => $line->notes ? $line->notes."\n".$linkNotes : $linkNotes,
            ]);

            $details = $journal !== null
                ? $journal->narration
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

            Log::info('Bank interest or fees recorded from reconciliation', [
                'bank_transaction_id' => $line->id,
                'transaction_id' => $leg->transaction_id,
                'reused_journal' => $journal === null,
                'bank_account' => $bankAccount->code,
                'interest' => $isInterest,
                'amount' => $amount,
            ]);

            return $line;
        });
    }

    /**
     * The bank leg of this line's own earlier charge journal
     * (reference BANKCHG-{line id}), when one exists — the re-record
     * after an unmatch re-matches instead of double-posting. Refuses
     * when the journal's bank leg is not the picked account (the
     * account was changed; silently re-posting would double-record).
     */
    protected function existingChargeLeg($entity, BankTransaction $line, Account $bankAccount): ?Ledger
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
                'This line already posted a bank charge journal (unmatching does not remove it) — match to its own bank account, or reverse the journal first.'
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
            throw new \InvalidArgumentException("The charge date falls in a locked period ({$date->toDateString()}).");
        }

        $entity = IfrsPosting::resolveEntity();
        if ($entity !== null && $this->locks->isDateBlocked($date, $entity)) {
            throw new \InvalidArgumentException(
                $this->locks->dateBlockedMessage($date, $entity)
                ?? "The charge date falls in a closed financial year ({$date->toDateString()})."
            );
        }
    }
}
