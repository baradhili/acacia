<?php

namespace Modules\Payroll\Tests;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\TimeEntry;
use App\Models\User;

/**
 * Scaffolds time-entry-backed (service work) invoices — exactly the
 * income the PSI assessment counts — shared by the payroll and PSI
 * test classes.
 */
trait BuildsServiceWork
{
    /** A time-entry-backed invoice: service work by construction. */
    protected function serviceInvoice(Client $client, float $subtotal, string $date = '2026-08-15'): Invoice
    {
        $entry = new TimeEntry;
        $entry->fill([
            'entry_date' => $date,
            'hours' => 10,
            'rate' => $subtotal / 10,
            'billable' => true,
            'description' => 'Consulting',
            'status' => 'approved',
        ]);
        $entry->user_id = User::factory()->create()->id;
        $entry->client_id = $client->id;
        $entry->save();

        $invoice = new Invoice;
        $invoice->fill([
            'invoice_number' => 'INV-'.uniqid(),
            'status' => Invoice::STATUS_SENT,
            'issue_date' => $date,
            'subtotal' => $subtotal,
            'tax_amount' => 0,
            'total' => $subtotal,
        ]);
        $invoice->client_id = $client->id;
        $invoice->save();

        $item = $invoice->items()->make([
            'description' => 'Consulting',
            'quantity' => 10,
            'unit_price' => $subtotal / 10,
            'tax_rate' => 0,
            'tax_amount' => 0,
            'total' => $subtotal,
        ]);
        $item->time_entry_id = $entry->id;
        $item->save();

        return $invoice;
    }
}
