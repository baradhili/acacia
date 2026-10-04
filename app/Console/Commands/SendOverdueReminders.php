<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Models\User;
use App\Notifications\OverdueReminderNotification;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

/**
 * Chases overdue invoices by email (daily 08:00,
 * overdue-reminders.log): for each Invoice::overdue() match at least
 * --days past due (default 1), mails the client and every admin an
 * OverdueReminderNotification (mail channel — nothing lands in the
 * notifications table), then stamps last_reminder_sent_at so the
 * 3-day re-send throttle knows a reminder went out. The sends are
 * synchronous (the statements:send precedent), so the stamp lands
 * only after delivery — a failed send throws before it and the
 * invoice stays eligible; a client without email still throttles,
 * because the admin copies count as the reminder going out.
 */
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
            // Whole days — diffInDays() carries fractions, and the
            // count is both displayed and passed to the notification.
            $daysOverdue = (int) Carbon::parse($invoice->due_date)->diffInDays(now());

            if ($daysOverdue < $minDays) {
                $this->line("Skipping invoice {$invoice->invoice_number} - only {$daysOverdue} days overdue (min: {$minDays})");
                $skipped++;

                continue;
            }

            // Re-send throttle (every 3 days). The reminders are mail
            // only — no database notification rows to read back — so
            // the command's own stamp is the record.
            if ($invoice->last_reminder_sent_at && $invoice->last_reminder_sent_at->diffInDays(now()) < 3) {
                $this->line("Skipping invoice {$invoice->invoice_number} - reminder sent {$invoice->last_reminder_sent_at->diffForHumans()}");
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
                    $admins = User::role('admin')->get();
                    foreach ($admins as $admin) {
                        Notification::send($admin, new OverdueReminderNotification($invoice, $daysOverdue));
                    }

                    $invoice->update(['last_reminder_sent_at' => now()]);

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
