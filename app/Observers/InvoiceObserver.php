<?php

namespace App\Observers;

use App\Models\Invoice;
use App\Models\PurchaseOrder;

class InvoiceObserver
{
    /**
     * Handle the Invoice "created" event.
     */
    public function created(Invoice $invoice): void
    {
        $this->recalculatePo($invoice->purchase_order_id);
    }

    /**
     * Handle the Invoice "updated" event.
     */
    public function updated(Invoice $invoice): void
    {
        if ($invoice->wasChanged('purchase_order_id')) {
            // Recalc both the old PO (invoice no longer counts against it)
            // and the new PO (invoice now counts against it).
            $this->recalculatePo($invoice->getOriginal('purchase_order_id'));
            $this->recalculatePo($invoice->purchase_order_id);
        } elseif ($invoice->wasChanged('status')) {
            // recalculateUsedAmount filters by status (excludes draft/cancelled),
            // so a cancel/send transition changes the PO's used amount. Total
            // changes are NOT handled here — those are owned by
            // Invoice::recalculateTotals() via refreshPurchaseOrderUsedAmount,
            // which runs whenever line items change (handling 'total' here too
            // would recalc the PO twice on every line-item edit).
            $this->recalculatePo($invoice->purchase_order_id);
        }
    }

    /**
     * Handle the Invoice "deleted" event.
     */
    public function deleted(Invoice $invoice): void
    {
        $this->recalculatePo($invoice->purchase_order_id);
    }

    /**
     * Handle the Invoice "restored" event.
     */
    public function restored(Invoice $invoice): void
    {
        $this->recalculatePo($invoice->purchase_order_id);
    }

    /**
     * Handle the Invoice "force deleted" event.
     */
    public function forceDeleted(Invoice $invoice): void
    {
        $this->recalculatePo($invoice->purchase_order_id);
    }

    /**
     * Recalculate the linked PO's used amount, if any.
     */
    protected function recalculatePo(?int $poId): void
    {
        if (!$poId) {
            return;
        }

        $po = PurchaseOrder::find($poId);
        if ($po) {
            $po->recalculateUsedAmount();
        }
    }
}
