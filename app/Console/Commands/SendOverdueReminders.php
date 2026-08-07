<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Notifications\OverdueReminderNotification;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

class SendOverdueReminders extends Command
{
    protected $signature = 'notifications:overdue-reminders
                            {--days=1 : Minimum days overdue to send reminder}
                            {--dry-run : Show what would be done without sending}';

    protected $description = 'Send overdue payment reminders to clients';

    public function handle(): int
    {
        $minDays = (int) $this->option('days');
        $dryRun = $this->option('dry-run');
        
        $overdueInvoices = Invoice::overdue()
            ->with('client')
            ->get();

        if ($overdueInvoices->isEmpty()) {
            $this->info('No overdue invoices found.');
            return Command::SUCCESS;
        }

        $this->info("Found {$overdueInvoices->count()} overdue invoices.");

        $sent = 0;
        $skipped = 0;

        foreach ($overdueInvoices as $invoice) {
            $daysOverdue = Carbon::parse($invoice->due_date)->diffInDays(now());
            
            if ($daysOverdue < $minDays) {
                $this->line("Skipping invoice {$invoice->invoice_number} - only {$daysOverdue} days overdue (min: {$minDays})");
                $skipped++;
                continue;
            }

            // Check if we should send based on frequency (every 3 days)
            $lastReminderSent = $invoice->notifications()
                ->where('type', OverdueReminderNotification::class)
                ->latest()
                ->first();

            if ($lastReminderSent && $lastReminderSent->pivot->created_at->diffInDays(now()) < 3) {
                $this->line("Skipping invoice {$invoice->invoice_number} - reminder sent recently");
                $skipped++;
                continue;
            }

            if ($dryRun) {
                $this->warn("Would send reminder for invoice {$invoice->invoice_number} to {$invoice->client->email}");
            } else {
                try {
                    // Send to client
                    if ($invoice->client && $invoice->client->email) {
                        Notification::send($invoice->client, new OverdueReminderNotification($invoice, $daysOverdue));
                    }
                    
                    // Also send to admin users
                    $admins = \App\Models\User::role('admin')->get();
                    foreach ($admins as $admin) {
                        Notification::send($admin, new OverdueReminderNotification($invoice, $daysOverdue));
                    }

                    $this->info("Sent reminder for invoice {$invoice->invoice_number} ({$daysOverdue} days overdue)");
                    $sent++;
                } catch (\Exception $e) {
                    $this->error("Failed to send reminder for invoice {$invoice->invoice_number}: {$e->getMessage()}");
                }
            }
        }

        $this->info("Done. Sent: {$sent}, Skipped: {$skipped}");

        return Command::SUCCESS;
    }
}
