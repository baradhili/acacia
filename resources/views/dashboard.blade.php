@extends('layouts.app')
@section('title', 'Dashboard')

@section('content')
<div x-data="widgetManager()">
    <!-- Toolbar -->
    <div class="flex justify-end mb-4 gap-2">
        <template x-if="!isEditing">
            <button 
                @click="toggleEdit()"
                class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 flex items-center gap-2"
            >
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
                </svg>
                Customize Dashboard
            </button>
        </template>
        <template x-if="isEditing">
            <div class="flex gap-2">
                <button 
                    @click="resetLayout()"
                    class="px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700"
                >
                    Reset Layout
                </button>
                <button 
                    @click="toggleEdit()"
                    class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700"
                >
                    Done Editing
                </button>
            </div>
        </template>
    </div>

    <!-- Edit Mode Instructions -->
    <div x-show="isEditing" class="mb-4 p-4 bg-blue-50 border border-blue-200 rounded-lg">
        <p class="text-blue-800 text-sm">
            <strong>Edit Mode:</strong> Drag widgets by their header (the blue handle) to reposition. 
            Click "Done Editing" to save.
        </p>
    </div>

    <!-- Widget Grid -->
    <div id="widget-grid" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
        <div class="widget-card" data-widget="TotalClientsWidget">@widget(\App\Widgets\TotalClientsWidget::class)</div>
        <div class="widget-card" data-widget="OutstandingInvoicesWidget">@widget(\App\Widgets\OutstandingInvoicesWidget::class)</div>
        <div class="widget-card" data-widget="HoursThisMonthWidget">@widget(\App\Widgets\HoursThisMonthWidget::class)</div>
        <div class="widget-card" data-widget="GstPayableWidget">@widget(\App\Widgets\GstPayableWidget::class)</div>
        <div class="widget-card md:col-span-2 lg:col-span-4" data-widget="CashFlowWidget">@widget(\App\Widgets\CashFlowWidget::class)</div>
        <div class="widget-card md:col-span-1 lg:col-span-2" data-widget="ARAgingWidget">@widget(\App\Widgets\ARAgingWidget::class)</div>
        <div class="widget-card md:col-span-1 lg:col-span-2" data-widget="BankBalanceWidget">@widget(\App\Widgets\BankBalanceWidget::class)</div>
        <div class="widget-card md:col-span-1 lg:col-span-1" data-widget="RecentInvoicesWidget">@widget(\App\Widgets\RecentInvoicesWidget::class)</div>
        <div class="widget-card md:col-span-1 lg:col-span-1" data-widget="RecentPaymentsWidget">@widget(\App\Widgets\RecentPaymentsWidget::class)</div>
        <div class="widget-card md:col-span-1 lg:col-span-2" data-widget="OutstandingPOBudgetsWidget">@widget(\App\Widgets\OutstandingPOBudgetsWidget::class)</div>
        <div class="widget-card md:col-span-1 lg:col-span-2" data-widget="UnbilledTimeWidget">@widget(\App\Widgets\UnbilledTimeWidget::class)</div>
        <div class="widget-card md:col-span-1 lg:col-span-2" data-widget="PnLTrendWidget">@widget(\App\Widgets\PnLTrendWidget::class)</div>
        <div class="widget-card md:col-span-1 lg:col-span-2" data-widget="QuickActionsWidget">@widget(\App\Widgets\QuickActionsWidget::class)</div>
        <div class="widget-card md:col-span-1 lg:col-span-2" data-widget="WelcomeWidget">@widget(\App\Widgets\WelcomeWidget::class)</div>
    </div>
</div>
@endsection

@push('styles')
<style>
.widget-card {
    transition: all 0.2s;
}
.widget-card:hover {
    z-index: 10;
}
.widget-card.sortable-ghost {
    opacity: 0.4;
}
.widget-handle {
    cursor: grab;
}
.widget-handle:active {
    cursor: grabbing;
}
</style>
@endpush