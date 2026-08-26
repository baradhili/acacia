<?php

namespace App\Services;

use Carbon\Carbon;
use IFRS\Models\Entity;
use IFRS\Models\LineItem;
use IFRS\Models\ReportingPeriod;
use IFRS\Models\Transaction;
use IFRS\Transactions\JournalEntry;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * Shared plumbing for posting payments to the IFRS ledger.
 * Payment::postToIFRS() and BillPayment::postToIFRS() both resolve their
 * entity and reporting period through this service, so the posting
 * prerequisites are hardened in one place.
 */
class IfrsPosting
{
    /**
     * Resolve the IFRS entity to post against: the authed user's entity if
     * available, otherwise the first entity. Returns null if none exist.
     * Works in queued jobs where no user is authed (falls back to a query).
     */
    public static function resolveEntity(): ?Entity
    {
        $user = null;
        try {
            $user = Auth::user();
        } catch (\Throwable $e) {
            // Auth not available (e.g. queued job) — fall through to query.
        }

        if ($user) {
            $entity = $user->entity;
            if ($entity) {
                return $entity;
            }
        }

        $entity = Entity::orderBy('id')->first();

        // EntityScope (the IFRS package's global scope) dereferences
        // Auth::user()->entity->id on every IFRS model query and fatals
        // with "property id on null" when the authed user has no entity.
        // Lend the user the fallback entity as an in-memory relation
        // (never persisted) so the scope — and the rest of this posting —
        // resolve to the same entity returned here.
        if ($entity && $user) {
            $user->setRelation('entity', $entity);
        }

        return $entity;
    }

    /**
     * Ensure an OPEN reporting period exists for the fiscal year $date falls
     * in (mirrors ReportController::getReportingPeriod() / IFRSSeeder).
     * Transaction::save() throws MissingReportingPeriod when the period row
     * is absent, and the seeder only creates the current FY — a payment dated
     * in any other fiscal year would fail forever without this. Idempotent:
     * firstOrCreate skips periods that already exist.
     */
    public static function ensureReportingPeriod($date, Entity $entity): ReportingPeriod
    {
        $year = ReportingPeriod::year(Carbon::parse($date), $entity);

        return ReportingPeriod::firstOrCreate(
            ['entity_id' => $entity->id, 'calendar_year' => $year],
            ['period_count' => 1, 'status' => ReportingPeriod::OPEN],
        );
    }

    /**
     * The IFRS package rejects transactions dated exactly at the reporting
     * period's start instant (that moment is reserved for Balance objects),
     * and a date-only payment date lands precisely on midnight of 1 July
     * for payments made on the first day of the fiscal year (year_start 7).
     * Nudge those by one second — same calendar day, valid transaction.
     *
     * Call after ensureReportingPeriod(); periodStart() needs no period row
     * but the transaction save that follows does.
     */
    public static function transactionDate($date, Entity $entity): Carbon
    {
        $date = Carbon::parse($date);

        return $date->equalTo(ReportingPeriod::periodStart($date, $entity))
            ? $date->addSecond()
            : $date;
    }

    /**
     * Post a mirrored reversal of an already-posted transaction: the main
     * account's `credited` flag is inverted and every line item (including
     * its applied Vats) is recreated as-is, so each ledger leg flips Dr/Cr
     * and the pair nets to zero. The reversal keeps the original
     * transaction_date so the period it was reported in stays clean.
     *
     * Used by Payment::void() / BillPayment::void() to undo posted ledgers.
     * Best-effort: returns the reversal transaction id, or null on failure
     * (original missing or any Throwable — logged, not thrown).
     */
    public static function reverseTransaction(int $transactionId, string $narration, string $reference): ?int
    {
        try {
            $original = Transaction::with('lineItems.appliedVats.vat')->find($transactionId);
            if (!$original) {
                Log::error('IFRS transaction not found for reversal', ['transaction_id' => $transactionId]);
                return null;
            }

            $entity = Entity::find($original->entity_id);
            if (!$entity) {
                Log::error('IFRS entity not found for reversal', [
                    'transaction_id' => $transactionId,
                    'entity_id' => $original->entity_id,
                ]);
                return null;
            }

            self::ensureReportingPeriod($original->transaction_date, $entity);

            $reversal = new JournalEntry([
                'transaction_date' => $original->transaction_date,
                'account_id' => $original->account_id,
                'credited' => !$original->credited,
                'entity_id' => $original->entity_id,
                'narration' => $narration,
                'reference' => $reference,
            ]);

            foreach ($original->lineItems as $line) {
                // Lines are persisted before addLineItem() — unsaved items
                // share a null id and the package silently drops all but
                // the first (same constraint as BillPayment::postToIFRS()).
                $reversalLine = LineItem::create([
                    'account_id' => $line->account_id,
                    'amount' => (float) $line->amount,
                    'quantity' => (float) $line->quantity,
                    'vat_inclusive' => $line->vat_inclusive,
                    'entity_id' => $original->entity_id,
                ]);

                foreach ($line->appliedVats as $appliedVat) {
                    $reversalLine->addVat($appliedVat->vat);
                }
                $reversalLine->save(); // persist the applied vats

                $reversal->addLineItem($reversalLine);
            }

            $reversal->post();

            Log::info('Posted IFRS reversal', [
                'transaction_id' => $transactionId,
                'reversal_id' => $reversal->id,
            ]);

            return $reversal->id;
        } catch (\Throwable $e) {
            Log::error('Failed to post IFRS reversal', [
                'transaction_id' => $transactionId,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
            ]);
            return null;
        }
    }
}
