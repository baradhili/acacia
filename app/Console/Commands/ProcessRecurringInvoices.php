<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessRecurringInvoices extends Command
{
    protected $signature = 'invoices:process-recurring';
    protected $description = 'Process recurring invoices and create new invoices based on their schedule';

    public function handle(): int
    {
        $this->info('Processing recurring invoices...');

        // Get all recurring invoices that are due
        $recurringInvoices = Invoice::where('is_recurring', true)
            ->whereNotNull('next_recurring_date')
            ->where('next_recurring_date', '<=', Carbon::today())
            ->whereIn('status', [Invoice::STATUS_PAID, Invoice::STATUS_DRAFT])
            ->get();

        $created = 0;

        foreach ($recurringInvoices as $originalInvoice) {
            try {
                DB::beginTransaction();

                // Create new invoice as draft
                $newInvoice = Invoice::createWithUniqueNumber([
                    'client_id' => $originalInvoice->client_id,
                    'issue_date' => Carbon::today()->toDateString(),
                    'due_date' => Carbon::today()->addDays($originalInvoice->payment_terms ?? 30)->toDateString(),
                    'status' => Invoice::STATUS_DRAFT,
                    'notes' => $originalInvoice->notes,
                    'terms' => $originalInvoice->terms,
                    'is_recurring' => true,
                    'recurring_frequency' => $originalInvoice->recurring_frequency,
                    'parent_invoice_id' => $originalInvoice->id,
                ]);

                // Copy items from original invoice
                foreach ($originalInvoice->items as $item) {
                    InvoiceItem::create([
                        'invoice_id' => $newInvoice->id,
                        'description' => $item->description,
                        'quantity' => $item->quantity,
                        'unit_price' => $item->unit_price,
                        'tax_rate' => $item->tax_rate,
                        'discount' => $item->discount,
                        'sort_order' => $item->sort_order,
                    ]);
                }

                // Update next recurring date based on frequency
                $nextDate = $this->calculateNextRecurringDate(
                    Carbon::today(),
                    $originalInvoice->recurring_frequency
                );

                $originalInvoice->update(['next_recurring_date' => $nextDate]);

                DB::commit();

                $this->info("Created invoice {$newInvoice->invoice_number} from recurring schedule");
                Log::info("Created recurring invoice {$newInvoice->invoice_number}", [
                    'original_invoice_id' => $originalInvoice->id,
                    'new_invoice_id' => $newInvoice->id,
                ]);

                $created++;

            } catch (\Exception $e) {
                DB::rollBack();
                $this->error("Failed to create recurring invoice: {$e->getMessage()}");
                Log::error("Failed to create recurring invoice", [
                    'original_invoice_id' => $originalInvoice->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info("Processed {$recurringInvoices->count()} recurring invoices, created {$created} new invoices.");

        return self::SUCCESS;
    }

    private function calculateNextRecurringDate(Carbon $currentDate, ?string $frequency): Carbon
    {
        return match ($frequency) {
            'daily' => $currentDate->copy()->addDay(),
            'weekly' => $currentDate->copy()->addWeek(),
            'monthly' => $currentDate->copy()->addMonth(),
            'quarterly' => $currentDate->copy()->addMonths(3),
            'yearly' => $currentDate->copy()->addYear(),
            default => $currentDate->copy()->addMonth(), // Default to monthly
        };
    }
}
