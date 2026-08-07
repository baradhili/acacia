@extends('layouts.app')
@section('title', 'Dashboard')
@section('content')

    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-6">
        <!-- Quick Stats -->
        @widget('totalClients')
        @widget('outstandingInvoices')
        @widget('hoursThisMonth')
        @widget('gstPayable')
    </div>

    <!-- Recent Activity & Quick Actions -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        @widget('quickActions')
        @widget('welcome')
    </div>

@endsection