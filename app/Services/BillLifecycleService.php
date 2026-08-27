<?php

namespace App\Services;

use App\Models\Bill;
use App\Models\BillPayment;
use App\Models\BillPaymentAllocation;
use App\Models\Prepayment;
use IFRS\Models\Account;
use IFRS\Models\Entity;
use IFRS\Models\LineItem;
use IFRS\Transactions\JournalEntry;
use Illuminate\Support\Facades\DB;

/**
 * Bill removal / payment unapplication with strict ledger handling.
 *
 * Bills never post to IFRS (cash basis — see BillPayment::postToIFRS()),
 * but their payments do. Removing a bill (or a payment's allocation to
 * it) therefore has to undo the ledger share that payment posted:
 *
 *   original posting:    Cr Bank / Dr Expense / Dr GST Receivable
 *   share reversal:      Dr Bank / Cr Expense / Cr GST Receivable
 *
 * The reversal is apportioned with the same cents math as the original
 * (BillPayment::allocationGroups()), so a payment allocated across
 * several bills only gives back the deleted bill's share — the other
 * bills' ledger legs are untouched. Posted entries are never mutated or
 * deleted; the mirrored JournalEntry nets them to zero and both stay
 * for audit.
 *
 * Unlike the best-effort posting paths, these methods are STRICT: a
 * failed reversal (e.g. the payment's reporting period is closed)
 * throws and rolls the whole operation back rather than leaving the
 * subledger and ledger out of sync.
 */
class BillLifecycleService
{
    /**
     * Remove a payment's allocation to a bill, reversing that
     * allocation's ledger share. When the payment has no allocations
     * left afterwards it is voided (without a second reversal — the
     * share reversal already covered everything it posted), and the
     * prepayment schedules funded for this bill's items are
     * neutralised. The bill reverts to open/overdue and becomes
     * editable again (the "unpay, then edit" workflow).
     *
     * @throws \RuntimeException when the payment is not allocated to
     *         this bill, or any ledger reversal fails (period closed,
     *         accounts/entity missing). The DB transaction rolls back.
     */
    public static function unapplyPayment(Bill $bill, BillPayment $payment): void
    {
        $allocation = $payment->allocations()->where('bill_id', $bill->id)->first();

        if (!$allocation) {
            throw new \RuntimeException(
                "Payment {$payment->payment_number} is not allocated to bill {$bill->bill_number}."
            );
        }

        DB::transaction(function () use ($bill, $payment, $allocation) {
            self::unapplyAllocation($bill, $allocation, $payment);
        });
    }

    /**
     * Delete a bill from any status. Payments applied to it are
     * unapplied (each with its mirrored ledger reversal; payments left
     * with no allocations are voided), prepayment schedules funded by
     * its items are reversed and detached (rows survive as audit —
     * otherwise the bill_items cascade would silently erase them), and
     * documents are removed along with their files.
     *
     * @throws \RuntimeException when any ledger reversal fails. The DB
     *         transaction rolls back and nothing is deleted.
     */
    public static function deleteBill(Bill $bill): int
    {
        $voidedPayments = 0;

        DB::transaction(function () use ($bill, &$voidedPayments) {
            $allocations = $bill->allocations()
                ->with('billPayment')
                ->get();

            foreach ($allocations as $allocation) {
                $payment = $allocation->billPayment;
                if (!$payment) {
                    // Orphaned allocation (payment already hard-deleted) —
                    // nothing in the ledger behind it, just drop it.
                    $allocation->delete();
                    continue;
                }

                if (self::unapplyAllocation($bill, $allocation, $payment)) {
                    $voidedPayments++;
                }
            }

            // Prepayment schedules funded by this bill's items that are
            // still active (their funding allocation was just unapplied,
            // or predates an earlier manual allocation removal): reverse
            // their posted amortisations, then detach from the items so
            // the bill_items cascade delete doesn't erase the audit rows.
            $itemIds = $bill->items()->pluck('id');
            $prepayments = Prepayment::whereIn('bill_item_id', $itemIds)
                ->where('status', Prepayment::STATUS_ACTIVE)
                ->get();

            foreach ($prepayments as $prepayment) {
                foreach ($prepayment->amortisations()
                    ->whereNotNull('ifrs_transaction_id')
                    ->whereNull('reversed_at')
                    ->get() as $entry
                ) {
                    PrepaymentService::reverseAmortisation($entry, throw: true);
                }

                $prepayment->forceFill([
                    'status' => Prepayment::STATUS_VOID,
                    'bill_item_id' => null,
                ])->save();
            }

            // The documentable morph has no FK cascade — remove the
            // document rows (the Document::deleting hook drops the
            // stored files) before the bill row goes.
            $bill->documents()->get()->each->delete();

            $bill->delete();
        });

        return $voidedPayments;
    }

    /**
     * Core unapply: reverse this allocation's ledger share, delete the
     * allocation, recompute the bill status, and void the payment when
     * nothing is allocated to it anymore. Must run inside a DB
     * transaction — callers wrap it.
     */
    /**
     * Core unapply: reverse this allocation's ledger share, delete the
     * allocation, recompute the bill status, neutralise the affected
     * prepayment schedules, and void the payment when nothing is
     * allocated to it anymore. Must run inside a DB transaction —
     * callers wrap it. Returns true when the payment was voided here.
     */
    private static function unapplyAllocation(Bill $bill, BillPaymentAllocation $allocation, BillPayment $payment): bool
    {
        $wasPosted = $payment->ifrs_payment_id !== null
            && $payment->status !== BillPayment::STATUS_VOID;

        if ($wasPosted) {
            self::reverseAllocationShare($bill, $payment, (float) $allocation->amount);
        }

        $allocation->delete();
        $bill->updateStatusFromPayments();

        // Prepayment schedules funded for this bill's items stop
        // amortising (their posted entries get mirrored reversals); a
        // shared payment's schedules for its other bills keep running.
        $payment->voidPrepayments($bill->items()->pluck('id')->all(), throw: true);

        // A payment with nothing left allocated no longer represents a
        // money movement (its whole posting has been reversed by the
        // per-allocation share reversals) — void it rather than leaving
        // a "completed" payment with a reversed ledger. Any remainder it
        // never allocated never hit the ledger either; record a fresh
        // payment instead of resurrecting this one.
        if (!$payment->allocations()->exists() && $payment->status !== BillPayment::STATUS_VOID) {
            $payment->update(['status' => BillPayment::STATUS_VOID]);
            return true;
        }

        return false;
    }

    /**
     * Post the mirrored reversal of ONE allocation's share of a payment
     * posting: Dr Bank / Cr Expense (per account + GST treatment) / Cr
     * GST Receivable, apportioned from the bill's items with the same
     * cents math as BillPayment::postToIFRS() so the share is exact.
     * Dated at the payment's date so it lands in the period the
     * original was reported in.
     *
     * @throws \RuntimeException on any posting prerequisite failure or
     *         Throwable from the IFRS package (closed reporting period,
     *         missing accounts, etc.) — never leaves a half-posted
     *         reversal.
     */
    private static function reverseAllocationShare(Bill $bill, BillPayment $payment, float $amount): void
    {
        $entity = IfrsPosting::resolveEntity();
        if (!$entity) {
            throw new \RuntimeException('No IFRS entity available for the reversal.');
        }

        $bankAccount = Account::where('code', BillPayment::IFRS_BANK_ACCOUNT_CODE)->first();
        $defaultExpenseAccount = Account::where('code', BillPayment::IFRS_DEFAULT_EXPENSE_ACCOUNT_CODE)->first();
        if (!$bankAccount || !$defaultExpenseAccount) {
            throw new \RuntimeException(
                'IFRS accounts not found (bank ' . BillPayment::IFRS_BANK_ACCOUNT_CODE
                . ' / expense ' . BillPayment::IFRS_DEFAULT_EXPENSE_ACCOUNT_CODE . ').'
            );
        }

        $groups = BillPayment::allocationGroups($bill, $amount, $defaultExpenseAccount);
        if (empty($groups)) {
            throw new \RuntimeException(
                "Nothing to reverse for bill {$bill->bill_number} — no allocatable bill items."
            );
        }

        try {
            IfrsPosting::ensureReportingPeriod($payment->payment_date, $entity);

            // Mirror of the original posting: main account Bank flipped
            // to a debit; line items (built with the same treatments)
            // land on the credit side.
            $reversal = new JournalEntry([
                'transaction_date' => IfrsPosting::transactionDate($payment->payment_date, $entity),
                'account_id' => $bankAccount->id,
                'credited' => false,
                'entity_id' => $entity->id,
                'narration' => "Reversal of {$bill->bill_number} share of supplier payment {$payment->payment_number}",
                'reference' => $payment->payment_number,
            ]);

            $gstVat = BillPayment::purchaseGstVat($entity);

            foreach ($groups as $key => $cents) {
                [$accountId, $treatment] = explode('-', $key);
                $vatInclusive = $treatment === 'gst';
                $taxable = in_array($treatment, ['gst', 'gstadd']) && $gstVat;

                // Same ex-GST back-out as postToIFRS() so the gstadd
                // legs reverse exactly what was posted.
                $lineAmount = ($treatment === 'gstadd' && $gstVat)
                    ? round($cents / (100 + (float) $gstVat->rate), 2)
                    : $cents / 100;

                // Persisted before addLineItem() — unsaved items share a
                // null id and the package silently drops all but the
                // first (same constraint as the posting paths).
                $line = LineItem::create([
                    'account_id' => (int) $accountId,
                    'amount' => $lineAmount,
                    'quantity' => 1,
                    'vat_inclusive' => $vatInclusive,
                    'entity_id' => $entity->id,
                ]);

                if ($taxable) {
                    $line->addVat($gstVat);
                    $line->save(); // persist the applied vat
                }

                $reversal->addLineItem($line);
            }

            $reversal->post();
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                "Failed to reverse the ledger share of payment {$payment->payment_number} "
                . "for bill {$bill->bill_number}: {$e->getMessage()}", 0, $e
            );
        }
    }
}
