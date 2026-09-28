<?php

namespace App\Widgets;

use App\Models\Invoice;
use Arrilot\Widgets\AbstractWidget;
use Illuminate\View\View;

/**
 * The ten most recently created invoices, any status — a working
 * queue (newest work on top), so creation time is the sort key, not
 * issue date; the card renders the newest five.
 */
class RecentInvoicesWidget extends AbstractWidget
{
    protected $config = [];

    public function run(): View
    {
        $invoices = Invoice::with('client')
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get()
            ->map(function ($invoice) {
                return [
                    'id' => $invoice->id,
                    'invoice_number' => $invoice->invoice_number,
                    'client_name' => $invoice->client?->name ?? __('widgets.unknown'),
                    'total' => $invoice->total,
                    'total_formatted' => number_format($invoice->total, 2),
                    'status' => $invoice->status,
                    'issue_date' => $invoice->issue_date?->format('Y-m-d'),
                    'due_date' => $invoice->due_date?->format('Y-m-d'),
                    'is_overdue' => $invoice->is_overdue,
                    'amount_due' => $invoice->amount_due,
                ];
            });

        return view('widgets.recent_invoices', [
            'invoices' => $invoices,
            'count' => $invoices->count(),
            'total_amount' => $invoices->sum('total'),
        ]);
    }
}
