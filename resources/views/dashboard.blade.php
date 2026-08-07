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

    <!-- Recent Activity & Quick Actions -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        @widget(\App\Widgets\QuickActionsWidget::class)
        @widget(\App\Widgets\WelcomeWidget::class)
    </div>

@endsection