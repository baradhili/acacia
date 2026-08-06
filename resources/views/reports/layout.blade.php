@extends('layouts.app')

@push('styles')
<style>
    .report-table {
        width: 100%;
        border-collapse: collapse;
    }
    .report-table th,
    .report-table td {
        padding: 8px 12px;
        border-bottom: 1px solid #e5e7eb;
        text-align: left;
    }
    .report-table th {
        background-color: #f9fafb;
        font-weight: 600;
        color: #374151;
    }
    .report-table tfoot td {
        font-weight: 700;
        background-color: #f9fafb;
        border-top: 2px solid #d1d5db;
    }
    .report-header {
        margin-bottom: 1.5rem;
    }
    .report-title {
        font-size: 1.5rem;
        font-weight: 700;
        color: #111827;
    }
    .report-subtitle {
        color: #6b7280;
        margin-top: 0.25rem;
    }
    .report-filters {
        display: flex;
        gap: 1rem;
        margin-bottom: 1.5rem;
        padding: 1rem;
        background-color: #f9fafb;
        border-radius: 0.5rem;
    }
    .report-actions {
        display: flex;
        gap: 0.5rem;
        margin-bottom: 1rem;
    }
</style>
@endpush

@section('content')
<div class="bg-white rounded-lg shadow">
    @yield('report-content')
</div>
@endsection
