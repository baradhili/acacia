@extends('layouts.app')
@section('title', 'Dashboard')
@section('content')

    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-6">
        <!-- Quick Stats -->
        @widget(\App\Widgets\TotalClientsWidget::class)
        @widget(\App\Widgets\OutstandingInvoicesWidget::class)
        @widget(\App\Widgets\HoursThisMonthWidget::class)
        @widget(\App\Widgets\GstPayableWidget::class)
    </div>

    <!-- Cash Flow -->
    <div class="mb-6">
        @widget(\App\Widgets\CashFlowWidget::class)
    </div>

    <!-- Two Column Layout -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
        @widget(\App\Widgets\ARAgingWidget::class)
        @widget(\App\Widgets\BankBalanceWidget::class)
    </div>

    <!-- Three Column Layout -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
        @widget(\App\Widgets\RecentInvoicesWidget::class)
        @widget(\App\Widgets\RecentPaymentsWidget::class)
        @widget(\App\Widgets\OutstandingPOBudgetsWidget::class)
    </div>

    <!-- Bottom Row -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
        @widget(\App\Widgets\UnbilledTimeWidget::class)
        @widget(\App\Widgets\PnLTrendWidget::class)
    </div>

    <!-- Quick Actions & Welcome -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        @widget(\App\Widgets\QuickActionsWidget::class)
        @widget(\App\Widgets\WelcomeWidget::class)
    </div>

@endsection