<?php

namespace Modules\Reconciliation\Services;

use App\Services\IfrsPosting;
use App\Services\PeriodLockService;
use Carbon\Carbon;
use IFRS\Models\Account;
use IFRS\Models\Balance;
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
 * Records the one movement the payment tiers never model: your own
 * money moving between bank accounts — either between two accounts
 * the books track (a Dr/Cr pair of BANK accounts), or in from / out
 * to an account the books do not track (Funds Introduced / Funds
 * Withdrawn equity — an injection or withdrawal is not income or
 * expense, so nothing touches revenue, GST or the BAS labels, and
 * the company tax report's equity branch already treats non-dividend
 * equity movement as a V05-explained non-assessable flow).
 *
 * Invoked from the match screen for a pending bank line: posts the
 * journal dated the line's own date (period locks refuse a locked or
 * closed date), then matches the line to the journal's bank-account
 * ledger row — one line, one journal, so payment-limit splits of one
 * intended transfer each record their own. The match deliberately
 * does NOT teach the counterparty rules: a transfer journal is a
 * one-off target, and a learned rule would point future lines from
 * the same external account at an already-reconciled journal.
 */
class BankTransferService
{
    /** Lazily created on existing charts; the 3xxx equity block. */
    public const FUNDS_INTRODUCED_CODE = 3500;

    public const FUNDS_WITHDRAWN_CODE = 3510;

    public function __construct(protected PeriodLockService $locks) {}

    /**
     * Post the transfer behind a pending bank line and match the
     * line to it. $bankAccountId is the tracked BANK account the
     * line moves; $counterpartAccountId is the tracked BANK account
     * on the other side, or null when that side is external to the
     * books. Throws InvalidArgumentException with a user-ready
     * message on any refusal; nothing posts on refusal.
     */
    public function record(BankTransaction $line, int $bankAccountId, ?int $counterpartAccountId, ?string $notes = null): BankTransaction
    {
        if ($line->status !== BankTransaction::STATUS_PENDING) {
            throw new \InvalidArgumentException('Only a pending bank line can be recorded as a transfer.');
        }

        $entity = IfrsPosting::resolveEntity();
        if ($entity === null) {
            throw new \InvalidArgumentException('No IFRS entity is configured.');
        }

        if ($line->currency !== $entity->currency?->currency_code) {
            throw new \InvalidArgumentException(
                "The line is in {$line->currency} but the books are kept in ".($entity->currency?->currency_code ?? '?')
                    .' — transfer journals only post in the entity\'s currency.'
            );
        }

        $amount = round(abs((float) $line->amount), 2);
        if ($amount < 0.005) {
            throw new \InvalidArgumentException('The line has no amount to transfer.');
        }

        // EntityScope off, entity filtered explicitly — the scope
        // cannot resolve an entity for every caller that can reach
        // this service with one resolved (the TaxReportController
        // precedent).
        $bankAccount = Account::withoutGlobalScope(EntityScope::class)
            ->where('entity_id', $entity->id)
            ->whereKey($bankAccountId)
            ->first();
        if (! $bankAccount || $bankAccount->account_type !== Account::BANK) {
            throw new \InvalidArgumentException('Pick a bank account from the chart of accounts.');
        }

        $counterpart = null;
        if ($counterpartAccountId !== null) {
            $counterpart = Account::withoutGlobalScope(EntityScope::class)
                ->where('entity_id', $entity->id)
                ->whereKey($counterpartAccountId)
                ->first();
            if (! $counterpart || $counterpart->account_type !== Account::BANK) {
                throw new \InvalidArgumentException('The other side must be a bank account, or external to the books.');
            }
            if ($counterpart->id === $bankAccount->id) {
                throw new \InvalidArgumentException('The two sides of a transfer must be different accounts.');
            }
        }

        $date = Carbon::parse($line->transaction_date)->startOfDay();
        $this->assertDatePostable($date);

        $moneyIn = $line->type === BankTransaction::TYPE_CREDIT;

        return DB::transaction(function () use ($line, $entity, $bankAccount, $counterpart, $amount, $date, $moneyIn, $notes) {
            // Authoritative pending check under the line's row lock:
            // a double-submit racing past the check above must not
            // post a second journal for the same line.
            $line = BankTransaction::whereKey($line->id)->lockForUpdate()->firstOrFail();
            if ($line->status !== BankTransaction::STATUS_PENDING) {
                throw new \InvalidArgumentException('Only a pending bank line can be recorded as a transfer.');
            }

            // The journal this line needs may already be posted: its
            // own (an unmatch returned the line to pending — the
            // movement never left the books), or, for tracked-account
            // pairs, the unclaimed leg of the journal the other side's
            // feed line recorded. Never double-post either way.
            $leg = $this->reusableTransferLeg($entity, $line, $bankAccount, $counterpart, $moneyIn, $amount, $date);

            $journal = null;
            if ($leg === null) {
                $debitAccount = $moneyIn
                    ? $bankAccount
                    : ($counterpart ?? $this->ensureEquityAccount(self::FUNDS_WITHDRAWN_CODE, 'Funds Withdrawn', $entity));
                $creditAccount = $moneyIn
                    ? ($counterpart ?? $this->ensureEquityAccount(self::FUNDS_INTRODUCED_CODE, 'Funds Introduced', $entity))
                    : $bankAccount;

                IfrsPosting::ensureReportingPeriod($date, $entity);

                $journal = new JournalEntry([
                    'transaction_date' => IfrsPosting::transactionDate($date, $entity),
                    'account_id' => $debitAccount->id,
                    'credited' => false,
                    'entity_id' => $entity->id,
                    'currency_id' => $entity->currency_id,
                    'narration' => $this->narration($line, $moneyIn, $bankAccount, $counterpart),
                    'reference' => 'XFER-'.$line->id,
                ]);
                $journal->addLineItem(LineItem::create([
                    'account_id' => $creditAccount->id,
                    'amount' => $amount,
                    'quantity' => 1,
                    'entity_id' => $entity->id,
                ]));
                $journal->post();

                // The bank leg's ledger row is the match target —
                // either leg would identify the transaction, but the
                // bank side is the one that keeps the panel's
                // labelling honest.
                $leg = Ledger::where('transaction_id', $journal->id)
                    ->where('post_account', $bankAccount->id)
                    ->orderBy('id')
                    ->first();
                if ($leg === null) {
                    throw new \InvalidArgumentException('The transfer journal posted without a bank leg — nothing to reconcile against.');
                }
            }

            $linkNotes = ($notes ?? ($journal !== null ? 'Recorded as a bank transfer' : 'Matched to the existing transfer journal'))
                .' on '.now()->toDateTimeString();
            $line->update([
                'status' => BankTransaction::STATUS_MATCHED,
                'matched_transaction_id' => $leg->id,
                'matched_transaction_type' => 'ledger',
                'matched_at' => now(),
                'notes' => $line->notes ? $line->notes."\n".$linkNotes : $linkNotes,
            ]);

            $details = $journal !== null
                ? $this->narration($line, $moneyIn, $bankAccount, $counterpart)
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

            Log::info('Bank transfer recorded from reconciliation', [
                'bank_transaction_id' => $line->id,
                'transaction_id' => $leg->transaction_id,
                'reused_journal' => $journal === null,
                'bank_account' => $bankAccount->code,
                'counterpart' => $counterpart?->code ?? 'external',
                'amount' => $amount,
            ]);

            return $line;
        });
    }

    /**
     * The bank leg this line should match to when the journal already
     * exists, so the movement never posts twice:
     *
     * - This line's own journal (reference XFER-{line id}) — an
     *   unmatch returned the line to pending but the movement never
     *   left the books, so recording again re-matches, and refuses if
     *   the accounts were changed rather than silently re-posting.
     *
     * - For tracked-account pairs, the unclaimed leg of the journal
     *   the OTHER side's feed line recorded — same accounts, amount
     *   and ±3 days, one bank leg per side, so a transfer feeding two
     *   accounts' statements is one journal, not two. Payment-limit
     *   splits cannot collide with this: each split's journal has its
     *   bank leg claimed by its own line the moment it posts.
     *
     * There is no reversal path for XFER journals today (unmatching
     * keeps the journal, correctly — the bank movement was real); a
     * future one must clear the line's match alongside, or this reuse
     * would re-match a reversed journal.
     */
    protected function reusableTransferLeg(
        $entity,
        BankTransaction $line,
        Account $bankAccount,
        ?Account $counterpart,
        bool $moneyIn,
        float $amount,
        Carbon $date,
    ): ?Ledger {
        // The line's own journal, whatever accounts it used.
        $ownTransactionId = Transaction::withoutGlobalScope(EntityScope::class)
            ->where('entity_id', $entity->id)
            ->where('reference', 'XFER-'.$line->id)
            ->value('id');
        if ($ownTransactionId !== null) {
            $leg = Ledger::where('transaction_id', $ownTransactionId)
                ->where('post_account', $bankAccount->id)
                ->orderBy('id')
                ->first();

            if ($leg === null) {
                throw new \InvalidArgumentException(
                    'This line already posted a transfer journal (unmatching does not remove it) — match to its own accounts, or reverse the journal first.'
                );
            }

            return $leg;
        }

        if ($counterpart === null) {
            return null; // external deposits are genuinely repeatable
        }

        $claimed = BankTransaction::matched()
            ->where('matched_transaction_type', 'ledger')
            ->pluck('matched_transaction_id');

        $candidates = DB::table((new Ledger)->getTable().' as l')
            ->join((new Transaction)->getTable().' as t', 't.id', '=', 'l.transaction_id')
            ->where('t.entity_id', $entity->id)
            ->where('t.reference', 'like', 'XFER-%')
            ->whereNull('t.deleted_at')
            ->whereNull('l.deleted_at')
            ->where('l.post_account', $bankAccount->id)
            ->where('l.entry_type', $moneyIn ? Balance::DEBIT : Balance::CREDIT)
            ->whereRaw('ABS(l.amount - ?) < 0.005', [$amount])
            ->whereBetween('t.transaction_date', [
                $date->copy()->subDays(3)->startOfDay(),
                $date->copy()->addDays(3)->endOfDay(),
            ])
            ->whereNotIn('l.id', $claimed)
            ->orderBy('t.transaction_date')
            ->get(['l.id', 't.id as transaction_id']);

        foreach ($candidates as $candidate) {
            $hasCounterpartLeg = Ledger::where('transaction_id', $candidate->transaction_id)
                ->where('post_account', $counterpart->id)
                ->exists();

            if ($hasCounterpartLeg) {
                return Ledger::find($candidate->id);
            }
        }

        return null;
    }

    /**
     * The Funds Introduced / Funds Withdrawn equity accounts — lazy
     * for existing charts, the ensureRoundingAccount precedent.
     */
    protected function ensureEquityAccount(int $code, string $name, $entity): Account
    {
        return Account::withoutGlobalScope(EntityScope::class)->firstOrCreate(
            ['entity_id' => $entity->id, 'code' => $code],
            [
                'account_type' => Account::EQUITY,
                'name' => $name,
                'currency_id' => $entity->currency_id,
            ],
        );
    }

    protected function narration(BankTransaction $line, bool $moneyIn, Account $bankAccount, ?Account $counterpart): string
    {
        if ($counterpart !== null) {
            return $moneyIn
                ? "Bank transfer — {$counterpart->name} to {$bankAccount->name}"
                : "Bank transfer — {$bankAccount->name} to {$counterpart->name}";
        }

        return $moneyIn
            ? "Funds introduced from an external account into {$bankAccount->name}"
            : "Funds withdrawn from {$bankAccount->name} to an external account";
    }

    /**
     * Refuse posting into a locked app period or a closed IFRS year —
     * the same guards the other journal-posting services apply.
     */
    protected function assertDatePostable(Carbon $date): void
    {
        if ($this->locks->isDateLocked($date)) {
            throw new \InvalidArgumentException("The transfer date falls in a locked period ({$date->toDateString()}).");
        }

        $entity = IfrsPosting::resolveEntity();
        if ($entity !== null && $this->locks->isDateBlocked($date, $entity)) {
            throw new \InvalidArgumentException(
                $this->locks->dateBlockedMessage($date, $entity)
                    ?? "The transfer date falls in a closed financial year ({$date->toDateString()})."
            );
        }
    }
}
