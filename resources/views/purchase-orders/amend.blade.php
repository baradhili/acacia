@extends('layouts.app')
@section('title', __('purchase_orders.amend_heading', ['number' => $purchaseOrder->po_number]))
@section('content')

    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-800">{{ __('purchase_orders.amend_heading', ['number' => $purchaseOrder->po_number]) }}</h1>
    </div>

    <!-- Current Terms -->
    <div class="bg-white rounded-lg shadow p-6 mb-6">
        <h3 class="text-lg font-semibold text-gray-800 mb-4">{{ __('purchase_orders.current_terms') }}</h3>
        <div class="grid grid-cols-1 md:grid-cols-5 gap-6">
            <div>
                <h4 class="text-sm font-medium text-gray-500">{{ __('purchase_orders.rate') }}</h4>
                <p class="mt-1 text-gray-900">${{ number_format($purchaseOrder->rate, 2) }}</p>
            </div>
            <div>
                <h4 class="text-sm font-medium text-gray-500">{{ __('purchase_orders.allocation') }}</h4>
                <p class="mt-1 text-gray-900">{{ number_format($purchaseOrder->allocation, 2) }}%</p>
            </div>
            <div>
                <h4 class="text-sm font-medium text-gray-500">{{ __('purchase_orders.business_days') }}</h4>
                <p class="mt-1 text-gray-900">{{ $purchaseOrder->business_days }}</p>
            </div>
            <div>
                <h4 class="text-sm font-medium text-gray-500">{{ __('purchase_orders.implied_budget') }}</h4>
                <p class="mt-1 text-gray-900">${{ number_format($purchaseOrder->budgeted_amount, 2) }}</p>
            </div>
            <div>
                <h4 class="text-sm font-medium text-gray-500">{{ __('purchase_orders.remaining') }}</h4>
                <p class="mt-1 text-gray-900">${{ number_format($purchaseOrder->remaining, 2) }}</p>
            </div>
        </div>
    </div>

    <!-- New Terms -->
    <div class="bg-white rounded-lg shadow p-6 mb-6">
        <h3 class="text-lg font-semibold text-gray-800 mb-4">{{ __('purchase_orders.new_terms') }}</h3>
        <form action="{{ route('purchase-orders.amend.store', $purchaseOrder) }}" method="POST">
            @csrf

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <label for="rate" class="block text-sm font-medium text-gray-700">{{ __('purchase_orders.rate') }} *</label>
                    <input type="number" name="rate" id="rate" value="{{ old('rate', $purchaseOrder->rate) }}" step="0.01" min="0" required oninput="poUpdatePreview()"
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-xs focus:border-indigo-500 focus:ring-indigo-500">
                    @error('rate')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="allocation" class="block text-sm font-medium text-gray-700">{{ __('purchase_orders.allocation') }} *</label>
                    <input type="number" name="allocation" id="allocation" value="{{ old('allocation', $purchaseOrder->allocation) }}" step="0.01" min="0.01" max="100" required oninput="poUpdatePreview()"
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-xs focus:border-indigo-500 focus:ring-indigo-500">
                    <p class="mt-1 text-sm text-gray-500">{{ __('purchase_orders.allocation_help') }}</p>
                    @error('allocation')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="start_date" class="block text-sm font-medium text-gray-700">Start Date *</label>
                    <input type="date" name="start_date" id="start_date" value="{{ old('start_date', $purchaseOrder->start_date?->format('Y-m-d')) }}" required onchange="poUpdatePreview()"
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-xs focus:border-indigo-500 focus:ring-indigo-500">
                    @error('start_date')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="end_date" class="block text-sm font-medium text-gray-700">End Date *</label>
                    <input type="date" name="end_date" id="end_date" value="{{ old('end_date', $purchaseOrder->end_date?->format('Y-m-d')) }}" required onchange="poUpdatePreview()"
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-xs focus:border-indigo-500 focus:ring-indigo-500">
                    @error('end_date')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div class="md:col-span-2">
                    <label for="reason" class="block text-sm font-medium text-gray-700">{{ __('purchase_orders.amendment_reason') }}</label>
                    <textarea name="reason" id="reason" rows="2"
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-xs focus:border-indigo-500 focus:ring-indigo-500">{{ old('reason') }}</textarea>
                    <p class="mt-1 text-sm text-gray-500">{{ __('purchase_orders.amendment_reason_help') }}</p>
                    @error('reason')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            <div class="mt-4 p-4 bg-indigo-50 border border-indigo-200 rounded-lg">
                <p class="text-sm text-indigo-800">
                    <strong>{{ __('purchase_orders.implied_budget') }}:</strong>
                    <span id="implied-budget-value">—</span>
                </p>
                <p id="implied-budget-detail" class="mt-1 text-xs text-indigo-600"
                    data-template="{{ __('purchase_orders.implied_budget_formula', ['days' => ':days', 'rate' => ':rate', 'allocation' => ':allocation']) }}"></p>
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <a href="{{ route('purchase-orders.show', $purchaseOrder) }}" class="bg-gray-300 hover:bg-gray-400 text-gray-800 px-4 py-2 rounded-lg">
                    Cancel
                </a>
                <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg">
                    {{ __('purchase_orders.amend_contract') }}
                </button>
            </div>
        </form>
    </div>

    <x-document-upload :model="$purchaseOrder" />
@push('scripts')
<script>
    function poBusinessDays(start, end) {
        let days = 0;
        const cursor = new Date(start + 'T00:00:00');
        const last = new Date(end + 'T00:00:00');
        while (cursor <= last) {
            const day = cursor.getDay();
            if (day !== 0 && day !== 6) {
                days++;
            }
            cursor.setDate(cursor.getDate() + 1);
        }
        return days;
    }

    function poUpdatePreview() {
        const value = document.getElementById('implied-budget-value');
        const detail = document.getElementById('implied-budget-detail');
        const rate = parseFloat(document.getElementById('rate').value);
        const allocation = parseFloat(document.getElementById('allocation').value);
        const start = document.getElementById('start_date').value;
        const end = document.getElementById('end_date').value;

        if (!rate || !allocation || !start || !end || new Date(start) > new Date(end)) {
            value.textContent = '—';
            detail.textContent = '';
            return;
        }

        const days = poBusinessDays(start, end);
        const budget = rate * days * (allocation / 100) * 8;
        value.textContent = '$' + budget.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        detail.textContent = detail.dataset.template
            .replace(':days', days)
            .replace(':rate', '$' + rate.toFixed(2))
            .replace(':allocation', allocation + '%');
    }

    document.addEventListener('DOMContentLoaded', poUpdatePreview);
</script>
@endpush
@endsection
