<?php

namespace App\Widgets;

use App\Models\PurchaseOrder;
use Arrilot\Widgets\AbstractWidget;

/**
 * Open and partially-used purchase orders that still have budget
 * remaining, largest remaining first; the card renders the top five
 * of the ten gathered. Spent, remaining and utilization come from
 * the PO model's own computation so the widget can never disagree
 * with the purchase-order screens.
 */
class OutstandingPOBudgetsWidget extends AbstractWidget
{
    protected $config = [];

    public function run()
    {
        $purchaseOrders = PurchaseOrder::with('project.client')
            ->whereIn('status', [PurchaseOrder::STATUS_OPEN, PurchaseOrder::STATUS_PARTIALLY_USED])
            ->get()
            ->map(function ($po) {
                $total = (float) $po->budgeted_amount;
                $spent = (float) $po->used_amount;
                $remaining = $po->remaining;
                $utilization = $po->utilization;

                return [
                    'id' => $po->id,
                    'po_number' => $po->po_number,
                    'project_name' => $po->project?->name ?? __('widgets.no_project'),
                    'client_name' => $po->project?->client?->name ?? __('widgets.unknown'),
                    'total' => $total,
                    'total_formatted' => number_format($total, 2),
                    'spent' => $spent,
                    'spent_formatted' => number_format($spent, 2),
                    'remaining' => $remaining,
                    'remaining_formatted' => number_format($remaining, 2),
                    'utilization' => round($utilization, 1),
                    'is_over_budget' => $spent > $total,
                ];
            })
            ->filter(function ($po) {
                return $po['remaining'] > 0;
            })
            ->sortBy('remaining')
            ->reverse()
            ->take(10)
            ->values();

        return view('widgets.outstanding_po_budgets', [
            'purchase_orders' => $purchaseOrders,
            'count' => $purchaseOrders->count(),
            'total_remaining' => $purchaseOrders->sum('remaining'),
            'total_remaining_formatted' => number_format($purchaseOrders->sum('remaining'), 2),
        ]);
    }
}
