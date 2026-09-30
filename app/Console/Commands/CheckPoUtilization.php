<?php

namespace App\Console\Commands;

use App\Models\PurchaseOrder;
use App\Models\User;
use App\Notifications\PurchaseOrderUtilizationNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

/**
 * Daily utilization sweep over open/partially_used purchase orders
 * (06:00, po-utilization.log). Recomputes each PO's used_amount from
 * its issued invoices — which can also flip PO status to
 * partially_used/completed — then emails every admin/accountant the
 * first time a PO crosses 80% and again at 100%: the notification
 * flags (reset when the PO is reopened) fire each threshold once.
 * Mail-only; no ledger entries.
 */
class CheckPoUtilization extends Command
{
    protected $signature = 'po:check-utilization';

    protected $description = 'Check purchase order utilization and send notifications at 80% and 100%';

    public function handle(): int
    {
        $this->info('Checking PO utilization...');

        $openPOs = PurchaseOrder::open()->get();
        $notified = 0;

        foreach ($openPOs as $po) {
            // Recalculate used amount first
            $po->recalculateUsedAmount();

            // Check 80% threshold
            if ($po->shouldNotify80Percent()) {
                $this->notifyAdmins($po, 80);
                $po->markNotified80();
                $notified++;
                $this->line("Notified 80% utilization for PO {$po->po_number}");
            }

            // Check 100% threshold
            if ($po->shouldNotify100Percent()) {
                $this->notifyAdmins($po, 100);
                $po->markNotified100();
                $notified++;
                $this->line("Notified 100% utilization for PO {$po->po_number}");
            }
        }

        $this->info("Completed. {$notified} notifications sent.");

        return Command::SUCCESS;
    }

    protected function notifyAdmins(PurchaseOrder $po, int $threshold): void
    {
        $admins = User::role(['admin', 'accountant'])->get();
        Notification::send($admins, new PurchaseOrderUtilizationNotification($po, $po->utilization, (string) $threshold));
    }
}
