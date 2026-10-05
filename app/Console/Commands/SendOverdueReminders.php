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
 * notifications table). Each recipient sends on its own: one address
 * failing never discards the recipients already notified, and the
 * 3-day throttle's last_reminder_sent_at stamp lands only once at
 * least one recipient actually received the reminder — nobody
 * notified means not stamped. The sends are synchronous (the
 * statements:send precedent), so delivery failures throw where they
 * are caught, and dry-runs stamp nothing.
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
        $failed = 0;

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
                // Per-recipient sends: one address failing must not
                // discard the recipients already notified — the stamp
                // below holds the throttle for them, so tomorrow's
                // retry re-attempts only what failed.
                $delivered = 0;
                $failedRecipients = 0;

                $send = function ($recipient, string $label) use ($invoice, $daysOverdue, &$delivered, &$failedRecipients): void {
                    try {
                        Notification::send($recipient, new OverdueReminderNotification($invoice, $daysOverdue));
                        $delivered++;
                    } catch (\Exception $e) {
                        $failedRecipients++;
                        $this->error("Failed to send reminder for invoice {$invoice->invoice_number} to {$label}: {$e->getMessage()}");
                    }
                };

                if ($invoice->client && $invoice->client->email) {
                    $send($invoice->client, $invoice->client->email);
                }

                foreach (User::role('admin')->get() as $admin) {
                    $send($admin, $admin->email ?? 'admin #'.$admin->id);
                }

                // The stamp means a reminder went out: only actual
                // deliveries set it, and it never marks an invoice
                // reminded when nobody was sent one.
                if ($delivered > 0) {
                    $invoice->update(['last_reminder_sent_at' => now()]);

                    $this->info("Sent reminder for invoice {$invoice->invoice_number} ({$daysOverdue} days overdue) to {$delivered} recipient(s)".($failedRecipients > 0 ? ", {$failedRecipients} failed" : ''));
                    $sent++;
                } else {
                    $this->error("Reminder for invoice {$invoice->invoice_number} reached no recipient".($failedRecipients > 0 ? " — {$failedRecipients} send(s) failed" : ' — no client email and no admin users'));
                    $failed++;
                }
            }
        }

        $this->info("Done. Sent: {$sent}, Skipped: {$skipped}".($failed > 0 ? ", Failed: {$failed}" : ''));

        return Command::SUCCESS;
    }
}
