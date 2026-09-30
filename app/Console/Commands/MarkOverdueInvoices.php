<?php

namespace App\Console\Commands;

use App\Models\CreditNote;
use App\Models\Invoice;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Flips sent/partially_paid invoices past their due_date to overdue
 * (daily 07:00, invoices-overdue.log). Idempotent: flipped invoices
 * leave the queried statuses, so re-runs only catch new candidates;
 * invoices with an active (non-void) credit note are skipped as
 * under adjustment. Each flip is Log::info'd; no email is sent —
 * the client notification is still a TODO in the code.
 */
class MarkOverdueInvoices extends Command
{
    protected $signature = 'invoices:mark-overdue';

    protected $description = 'Mark sent invoices as overdue if past due date';

    public function handle(): int
    {
        $this->info('Checking for overdue invoices...');

        $overdueInvoices = Invoice::whereIn('status', [
            Invoice::STATUS_SENT,
            Invoice::STATUS_PARTIALLY_PAID,
        ])
            ->where('due_date', '<', now()->toDateString())
        // Invoices with an active credit note are under adjustment —
        // they are not dunned as overdue.
            ->whereDoesntHave('creditNotes', fn ($q) => $q->where('status', '!=', CreditNote::STATUS_VOID))
            ->get();

        $count = 0;
        foreach ($overdueInvoices as $invoice) {
            if ($invoice->status !== Invoice::STATUS_OVERDUE) {
                $invoice->update(['status' => Invoice::STATUS_OVERDUE]);
                $count++;

                // Log the change
                Log::info("Invoice {$invoice->invoice_number} marked as overdue", [
                    'invoice_id' => $invoice->id,
                    'due_date' => $invoice->due_date->toDateString(),
                ]);

                // TODO: Send overdue notification email to client
                // Mail::to($invoice->client->email)->send(new InvoiceOverdueMail($invoice));
            }
        }

        $this->info("Marked {$count} invoice(s) as overdue.");

        return Command::SUCCESS;
    }
}
