<?php

namespace App\Widgets;

use App\Models\Invoice;
use Illuminate\Support\Collection;
use Arrilot\Widgets\AbstractWidget;

class RecentInvoicesWidget extends AbstractWidget
{
    protected $config = [];

    public function run(): \Illuminate\View\View
    {
        $invoices = Invoice::with('client')
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get()
            ->map(function ($invoice) {
                return [
                    'id' => $invoice->id,
                    'invoice_number' => $invoice->invoice_number,
                    'client_name' => $invoice->client?->name ?? 'Unknown',
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
