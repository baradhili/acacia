<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DashboardService;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    protected DashboardService $dashboardService;

    public function __construct(DashboardService $dashboardService)
    {
        $this->dashboardService = $dashboardService;
    }

    /**
     * Get all dashboard widgets
     */
    public function index(): JsonResponse
    {
        $widgets = $this->dashboardService->getAllWidgets();

        return response()->json([
            'success' => true,
            'data' => $widgets,
        ]);
    }

    /**
     * Get specific widget
     */
    public function widget(string $widget): JsonResponse
    {
        $method = 'get' . str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $widget))) . 'Widget';
        
        if (!method_exists($this->dashboardService, $method)) {
            return response()->json([
                'success' => false,
                'message' => "Widget '{$widget}' not found",
            ], 404);
        }

        $data = $this->dashboardService->$method();

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    /**
     * Get cash flow widget
     */
    public function cashFlow(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->dashboardService->getCashFlowWidget(),
        ]);
    }

    /**
     * Get AR aging widget
     */
    public function arAging(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->dashboardService->getARAgingWidget(),
        ]);
    }

    /**
     * Get recent invoices widget
     */
    public function recentInvoices(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->dashboardService->getRecentInvoicesWidget(),
        ]);
    }

    /**
     * Get recent payments widget
     */
    public function recentPayments(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->dashboardService->getRecentPaymentsWidget(),
        ]);
    }

    /**
     * Get outstanding PO budgets widget
     */
    public function outstandingPOBudgets(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->dashboardService->getOutstandingPOBudgetsWidget(),
        ]);
    }

    /**
     * Get unbilled time widget
     */
    public function unbilledTime(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->dashboardService->getUnbilledTimeWidget(),
        ]);
    }

    /**
     * Get bank balance widget
     */
    public function bankBalance(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->dashboardService->getBankBalanceWidget(),
        ]);
    }

    /**
     * Get P&L trend widget
     */
    public function pnlTrend(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->dashboardService->getPnLTrendWidget(),
        ]);
    }
}
