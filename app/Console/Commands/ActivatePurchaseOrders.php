<?php

namespace App\Console\Commands;

use App\Models\PurchaseOrder;
use Illuminate\Console\Command;

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
