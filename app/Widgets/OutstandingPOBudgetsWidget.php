<?php

namespace App\Widgets;

use App\Models\PurchaseOrder;
use Arrilot\Widgets\AbstractWidget;

class OutstandingPOBudgetsWidget extends AbstractWidget
{
    protected $config = [];

    public function run()
    {
        $purchaseOrders = PurchaseOrder::with('project.client')
            ->whereIn('status', [PurchaseOrder::STATUS_OPEN, PurchaseOrder::STATUS_PARTIALLY_USED])
            ->get()
            ->map(function ($po) {
                $spent = $po->getSpentAmount();
                $remaining = $po->total - $spent;
                $utilization = $po->total > 0 ? ($spent / $po->total) * 100 : 0;

                return [
                    'id' => $po->id,
                    'po_number' => $po->po_number,
                    'project_name' => $po->project?->name ?? 'No Project',
                    'client_name' => $po->project?->client?->name ?? 'Unknown',
                    'total' => $po->total,
                    'total_formatted' => number_format($po->total, 2),
                    'spent' => $spent,
                    'spent_formatted' => number_format($spent, 2),
                    'remaining' => $remaining,
                    'remaining_formatted' => number_format($remaining, 2),
                    'utilization' => round($utilization, 1),
                    'is_over_budget' => $spent > $po->total,
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
