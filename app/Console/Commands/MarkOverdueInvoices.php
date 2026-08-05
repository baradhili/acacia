<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class MarkOverdueInvoices extends Command
{
    protected $signature = 'invoices:mark-overdue';
    protected $description = 'Mark sent invoices as overdue if past due date';

    public function handle(): int
    {
        $this->info('Checking for overdue invoices...');

        $overdueInvoices = Invoice::whereIn('status', [
            Invoice::STATUS_SENT,
            Invoice::STATUS_VIEWED,
            Invoice::STATUS_PARTIALLY_PAID,
        ])
        ->where('due_date', '<', now()->toDateString())
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
