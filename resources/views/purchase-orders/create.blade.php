@extends('layouts.app')
@section('title', __('purchase_orders.create_heading'))
@section('content')

    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-800">{{ __('purchase_orders.create_heading') }}</h1>
    </div>

    <div class="bg-white rounded-lg shadow p-6">
        <form action="{{ route('purchase-orders.store') }}" method="POST">
            @csrf

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <label for="client_id" class="block text-sm font-medium text-gray-700">Client *</label>
                    <select name="client_id" id="client_id" required
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-xs focus:border-indigo-500 focus:ring-indigo-500">
                        <option value="">Select Client</option>
                        @foreach($clients as $id => $name)
                            <option value="{{ $id }}" {{ old('client_id', $selectedClient?->id) == $id ? 'selected' : '' }}>{{ $name }}</option>
                        @endforeach
                    </select>
                    @error('client_id')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="title" class="block text-sm font-medium text-gray-700">Title *</label>
                    <input type="text" name="title" id="title" value="{{ old('title') }}" required
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-xs focus:border-indigo-500 focus:ring-indigo-500">
                    @error('title')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div class="md:col-span-2">
                    <label for="type" class="block text-sm font-medium text-gray-700">{{ __('purchase_orders.document_type') }} *</label>
                    <select name="type" id="type" onchange="poToggleType(this.value)"
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-xs focus:border-indigo-500 focus:ring-indigo-500">
                        <option value="purchase_order" {{ old('type', 'purchase_order') === 'purchase_order' ? 'selected' : '' }}>{{ __('purchase_orders.purchase_order') }}</option>
                        <option value="contract" {{ old('type') === 'contract' ? 'selected' : '' }}>{{ __('purchase_orders.contract') }}</option>
                    </select>
                    <p class="mt-1 text-sm text-gray-500">{{ __('purchase_orders.document_type_help') }}</p>
                    @error('type')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div class="md:col-span-2">
                    <label for="description" class="block text-sm font-medium text-gray-700">Description</label>
                    <textarea name="description" id="description" rows="2"
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-xs focus:border-indigo-500 focus:ring-indigo-500">{{ old('description') }}</textarea>
                </div>

                <div id="po-budget-field" class="hidden">
                    <label for="budgeted_amount" class="block text-sm font-medium text-gray-700">Budgeted Amount ($) *</label>
                    <input type="number" name="budgeted_amount" id="budgeted_amount" value="{{ old('budgeted_amount') }}" step="0.01" min="0"
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-xs focus:border-indigo-500 focus:ring-indigo-500">
                    @error('budgeted_amount')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div id="contract-rate-field" class="hidden">
                    <label for="rate" class="block text-sm font-medium text-gray-700">{{ __('purchase_orders.rate') }} *</label>
                    <input type="number" name="rate" id="rate" value="{{ old('rate') }}" step="0.01" min="0" oninput="poUpdatePreview()"
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-xs focus:border-indigo-500 focus:ring-indigo-500">
                    @error('rate')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div id="contract-allocation-field" class="hidden">
                    <label for="allocation" class="block text-sm font-medium text-gray-700">{{ __('purchase_orders.allocation') }} *</label>
                    <input type="number" name="allocation" id="allocation" value="{{ old('allocation', 100) }}" step="0.01" min="0.01" max="100" oninput="poUpdatePreview()"
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-xs focus:border-indigo-500 focus:ring-indigo-500">
                    <p class="mt-1 text-sm text-gray-500">{{ __('purchase_orders.allocation_help') }}</p>
                    @error('allocation')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="start_date" class="block text-sm font-medium text-gray-700">
                        Start Date <span id="start-date-required" class="text-red-600 hidden">*</span>
                    </label>
                    <input type="date" name="start_date" id="start_date" value="{{ old('start_date') }}" onchange="poUpdatePreview()"
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-xs focus:border-indigo-500 focus:ring-indigo-500">
                    @error('start_date')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="end_date" class="block text-sm font-medium text-gray-700">
                        End Date <span id="end-date-required" class="text-red-600 hidden">*</span>
                    </label>
                    <input type="date" name="end_date" id="end_date" value="{{ old('end_date') }}" onchange="poUpdatePreview()"
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-xs focus:border-indigo-500 focus:ring-indigo-500">
                    @error('end_date')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            <div id="contract-preview" class="hidden mt-4 p-4 bg-indigo-50 border border-indigo-200 rounded-lg">
                <p class="text-sm text-indigo-800">
                    <strong>{{ __('purchase_orders.implied_budget') }}:</strong>
                    <span id="implied-budget-value">—</span>
                </p>
                <p id="implied-budget-detail" class="mt-1 text-xs text-indigo-600"
                    data-template="{{ __('purchase_orders.implied_budget_formula', ['days' => ':days', 'rate' => ':rate', 'allocation' => ':allocation']) }}"></p>
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <a href="{{ route('purchase-orders.index') }}" class="bg-gray-300 hover:bg-gray-400 text-gray-800 px-4 py-2 rounded-lg">
                    Cancel
                </a>
                <button type="submit" id="po-submit-label" class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg">
                    {{ __('purchase_orders.create_button', ['type' => __('purchase_orders.purchase_order')]) }}
                </button>
            </div>
        </form>
    </div>

    <!-- Document Upload Info -->
    <div class="bg-blue-50 rounded-lg shadow p-6 mt-6 border border-blue-200">
        <div class="flex items-center">
            <svg class="h-5 w-5 text-blue-500 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            <p class="text-sm text-blue-800">
                <strong>Note:</strong> You can upload documents after creating the purchase order in the edit view.
            </p>
        </div>
    </div>

@push('scripts')
<script>
    // Server-side rules are authoritative; this toggle only keeps the
    // form in step — the inactive type's inputs are disabled so they
    // never submit, and required flags match the type branch.
    function poToggleType(type) {
        const isPo = type === 'purchase_order';
        const isContract = type === 'contract';

        document.getElementById('po-budget-field').classList.toggle('hidden', !isPo);
        const budget = document.getElementById('budgeted_amount');
        budget.disabled = !isPo;
        budget.required = isPo;

        ['contract-rate-field', 'contract-allocation-field'].forEach(function (id) {
            document.getElementById(id).classList.toggle('hidden', !isContract);
        });
        ['rate', 'allocation'].forEach(function (id) {
            const input = document.getElementById(id);
            input.disabled = !isContract;
            input.required = isContract;
        });

        ['start-date-required', 'end-date-required'].forEach(function (id) {
            document.getElementById(id).classList.toggle('hidden', !isContract);
        });
        document.getElementById('start_date').required = isContract;
        document.getElementById('end_date').required = isContract;

        document.getElementById('contract-preview').classList.toggle('hidden', !isContract);

        document.getElementById('po-submit-label').textContent = isContract
            ? @js(__('purchase_orders.create_button', ['type' => __('purchase_orders.contract')]))
            : @js(__('purchase_orders.create_button', ['type' => __('purchase_orders.purchase_order')]));
    }

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

    document.addEventListener('DOMContentLoaded', function () {
        poToggleType(document.getElementById('type').value);
        poUpdatePreview();
    });
</script>
@endpush

@endsection
