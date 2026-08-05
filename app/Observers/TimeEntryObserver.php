<?php

namespace App\Observers;

use App\Models\PurchaseOrder;
use App\Models\TimeEntry;

class TimeEntryObserver
{
    /**
     * Handle the TimeEntry "created" event.
     */
    public function created(TimeEntry $timeEntry): void
    {
        $this->recalculatePoIfLinked($timeEntry);
    }

    /**
     * Handle the TimeEntry "updated" event.
     */
    public function updated(TimeEntry $timeEntry): void
    {
        // Check if PO relationship changed
        if ($timeEntry->wasChanged('purchase_order_id')) {
            // Recalculate old PO if exists
            if ($timeEntry->getOriginal('purchase_order_id')) {
                $this->recalculatePo($timeEntry->getOriginal('purchase_order_id'));
            }
            // Recalculate new PO if exists
            if ($timeEntry->purchase_order_id) {
                $this->recalculatePo($timeEntry->purchase_order_id);
            }
        }

        // Recalculate if hours, rate, or billable status changed on approved entry
        if ($timeEntry->status === TimeEntry::STATUS_APPROVED && 
            ($timeEntry->wasChanged(['hours', 'rate', 'billable'])) &&
            $timeEntry->purchase_order_id) {
            $this->recalculatePo($timeEntry->purchase_order_id);
        }
    }

    /**
     * Handle the TimeEntry "deleted" event.
     */
    public function deleted(TimeEntry $timeEntry): void
    {
        $this->recalculatePoIfLinked($timeEntry);
    }

    /**
     * Handle the TimeEntry "restored" event.
     */
    public function restored(TimeEntry $timeEntry): void
    {
        $this->recalculatePoIfLinked($timeEntry);
    }

    /**
     * Handle the TimeEntry "force deleted" event.
     */
    public function forceDeleted(TimeEntry $timeEntry): void
    {
        $this->recalculatePoIfLinked($timeEntry);
    }

    /**
     * Recalculate PO used amount if the entry is linked to one.
     */
    protected function recalculatePoIfLinked(TimeEntry $timeEntry): void
    {
        if ($timeEntry->purchase_order_id) {
            $this->recalculatePo($timeEntry->purchase_order_id);
        }
    }

    /**
     * Recalculate PO used amount and update status.
     */
    protected function recalculatePo(int $poId): void
    {
        $po = PurchaseOrder::find($poId);
        if ($po) {
            $po->recalculateUsedAmount();
        }
    }
}
