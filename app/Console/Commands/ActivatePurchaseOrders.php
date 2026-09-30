<?php

namespace App\Console\Commands;

use App\Models\PurchaseOrder;
use Illuminate\Console\Command;

/**
 * Activates draft purchase orders on their start date (start_date <=
 * today, so missed runs catch up) via PurchaseOrder::activate(), the
 * draft -> open transition. Scheduled daily at 01:00
 * (routes/console.php, output to po-activation.log). Idempotent: an
 * activated PO leaves draft status and is never matched again. No
 * notifications fire and no ledger entries post here.
 */
class ActivatePurchaseOrders extends Command
{
    protected $signature = 'po:activate-pending';

    protected $description = 'Activate draft purchase orders that have start_date today';

    public function handle(): int
    {
        $this->info('Checking for POs to activate...');

        $today = now()->toDateString();

        $posToActivate = PurchaseOrder::where('status', PurchaseOrder::STATUS_DRAFT)
            ->whereNotNull('start_date')
            ->whereDate('start_date', '<=', $today)
            ->get();

        if ($posToActivate->isEmpty()) {
            $this->info('No purchase orders to activate.');

            return Command::SUCCESS;
        }

        $activated = 0;
        foreach ($posToActivate as $po) {
            $po->activate();
            $activated++;
            $this->line("Activated PO {$po->po_number}");
        }

        $this->info("Activated {$activated} purchase order(s).");

        return Command::SUCCESS;
    }
}
