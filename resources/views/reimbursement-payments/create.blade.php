@extends('layouts.app')
@section('title', 'Pay Employee Reimbursement')
@section('content')

    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-800">Pay Employee Reimbursement</h1>
    </div>

    @if (session('error'))
        <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
            {{ session('error') }}
        </div>
    @endif
    @if ($errors->any())
        <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
            <ul class="list-disc list-inside text-sm">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form action="{{ route('reimbursement-payments.store') }}" method="POST" class="space-y-6">
        @csrf

        <div class="bg-white rounded-lg shadow p-6">
            <h2 class="text-lg font-semibold text-gray-800 mb-4">Reimbursement Details</h2>

            <p class="text-sm text-gray-600 mb-4">
                This pays an employee back for expenses they paid out of pocket — it clears their
                Employee Reimbursements Payable balance (Dr 2280 / Cr Bank) and matches against the
                bank transfer in reconciliation. The expense and GST were already claimed when the
                employee-paid supplier payment was approved.
            </p>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Employee *</label>
                    <select name="employee_id" id="employeeSelect" required
                        class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 w-full">
                        <option value="">Select Employee</option>
                        @foreach ($employees as $id => $name)
                            <option value="{{ $id }}" data-outstanding="{{ $outstanding[$id] }}"
                                {{ old('employee_id', request('employee_id')) == $id ? 'selected' : '' }}>
                                {{ $name }} — owed ${{ number_format($outstanding[$id], 2) }}
                            </option>
                        @endforeach
                    </select>
                    @error('employee_id')
                        <p class="text-red-500 text-sm mt-1">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Amount *</label>
                    <div class="relative">
                        <span class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-500">$</span>
                        <input type="number" name="amount" id="amountInput" value="{{ old('amount') }}" step="0.01"
                            min="0.01" required
                            class="pl-7 rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 w-full">
                    </div>
                    @error('amount')
                        <p class="text-red-500 text-sm mt-1">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Payment Date *</label>
                    <input type="date" name="payment_date" value="{{ old('payment_date', now()->toDateString()) }}"
                        required
                        class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 w-full">
                    @error('payment_date')
                        <p class="text-red-500 text-sm mt-1">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Payment Method *</label>
                    <select name="payment_method" required
                        class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 w-full">
                        @foreach ($paymentMethods as $value => $label)
                            <option value="{{ $value }}" {{ old('payment_method') == $value ? 'selected' : '' }}>
                                {{ $label }}</option>
                        @endforeach
                    </select>
                    @error('payment_method')
                        <p class="text-red-500 text-sm mt-1">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Reference</label>
                    <input type="text" name="reference" value="{{ old('reference') }}"
                        class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 w-full"
                        placeholder="Bank transfer reference, etc.">
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Notes</label>
                    <input type="text" name="notes" value="{{ old('notes') }}"
                        class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 w-full">
                </div>
            </div>
        </div>

        <div class="flex justify-end gap-4">
            <a href="{{ route('reimbursement-payments.index') }}"
                class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-6 py-2 rounded-lg">
                Cancel
            </a>
            <button type="submit" class="bg-green-600 hover:bg-green-700 text-white px-6 py-2 rounded-lg">
                Record Reimbursement
            </button>
        </div>
    </form>

@endsection

@push('scripts')
    <script>
        // Pre-fill the amount with what the company owes the picked
        // employee (still editable — partial reimbursements are fine).
        const employeeSelect = document.getElementById('employeeSelect');
        const amountInput = document.getElementById('amountInput');
        function prefillAmount() {
            const option = employeeSelect.selectedOptions[0];
            const outstanding = parseFloat(option?.dataset.outstanding ?? '0');
            if (outstanding > 0 && !amountInput.value) {
                amountInput.value = outstanding.toFixed(2);
            }
        }
        employeeSelect.addEventListener('change', prefillAmount);
        prefillAmount();
    </script>
@endpush
