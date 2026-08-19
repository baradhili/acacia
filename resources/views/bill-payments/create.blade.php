@extends('layouts.app')
@section('title', 'Record Supplier Payment')
@section('content')

    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-800">Record Supplier Payment</h1>
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

    <form action="{{ route('bill-payments.store') }}" method="POST" class="space-y-6">
        @csrf

        <div class="bg-white rounded-lg shadow p-6">
            <h2 class="text-lg font-semibold text-gray-800 mb-4">Payment Details</h2>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Supplier *</label>
                    <select name="supplier_id" id="supplierSelect" required
                        class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 w-full">
                        <option value="">Select Supplier</option>
                        @foreach ($suppliers as $id => $name)
                            <option value="{{ $id }}"
                                {{ old('supplier_id', $selectedSupplier?->id) == $id ? 'selected' : '' }}>{{ $name }}
                            </option>
                        @endforeach
                    </select>
                    @error('supplier_id')
                        <p class="text-red-500 text-sm mt-1">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Amount *</label>
                    <div class="relative">
                        <span class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-500">$</span>
                        <input type="number" name="amount" value="{{ old('amount') }}" step="0.01" min="0.01"
                            required
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
                        placeholder="Transaction ID, Cheque #, etc.">
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Notes</label>
                    <input type="text" name="notes" value="{{ old('notes') }}"
                        class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 w-full">
                </div>
            </div>
        </div>

        <div class="bg-white rounded-lg shadow p-6">
            <h2 class="text-lg font-semibold text-gray-800 mb-4">Payment Allocation</h2>

            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-2">Allocate payment to bills</label>
                <div class="flex gap-4">
                    <label class="flex items-center">
                        <input type="radio" name="allocate_type" value="manual" checked
                            class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                        <span class="ml-2 text-sm text-gray-700">Manual allocation</span>
                    </label>
                    <label class="flex items-center">
                        <input type="radio" name="allocate_type" value="no"
                            class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                        <span class="ml-2 text-sm text-gray-700">Leave unallocated</span>
                    </label>
                </div>
            </div>

            <div id="manualAllocation">
                <p class="text-sm text-gray-600 mb-4">
                    Select bills to allocate payment to. Select a supplier first to see outstanding bills.
                </p>
                <div id="billsList" class="space-y-2">
                    <!-- Bills will be loaded dynamically -->
                </div>
            </div>
        </div>

        <div class="flex justify-end gap-4">
            <a href="{{ route('bill-payments.index') }}"
                class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-6 py-2 rounded-lg">
                Cancel
            </a>
            <button type="submit" class="bg-green-600 hover:bg-green-700 text-white px-6 py-2 rounded-lg">
                Record Payment
            </button>
        </div>
    </form>

@endsection

@push('scripts')
    <script>
        document.querySelectorAll('input[name="allocate_type"]').forEach(radio => {
            radio.addEventListener('change', function() {
                const manualSection = document.getElementById('manualAllocation');
                if (this.value === 'manual') {
                    manualSection.classList.remove('hidden');
                    loadSupplierBills();
                } else {
                    manualSection.classList.add('hidden');
                }
            });
        });

        document.getElementById('supplierSelect').addEventListener('change', function() {
            if (document.querySelector('input[name="allocate_type"]:checked').value === 'manual') {
                loadSupplierBills();
            }
        });

        function loadSupplierBills() {
            const supplierId = document.getElementById('supplierSelect').value;
            const billsList = document.getElementById('billsList');

            if (!supplierId) {
                billsList.innerHTML = '<p class="text-gray-500">Select a supplier to see outstanding bills.</p>';
                return;
            }

            // Fetch outstanding bills for this supplier
            fetch(`/bill-payments/supplier-bills/${supplierId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.length === 0) {
                        billsList.innerHTML =
                        '<p class="text-gray-500">No outstanding bills for this supplier.</p>';
                        return;
                    }

                    let html = '';
                    data.forEach(bill => {
                        // The explicit index (the bill id) is essential: bare
                        // [] never pairs [bill_id] and [amount] into one row
                        // in PHP, which made allocation validation fail
                        // silently.
                        html += `
                        <label class="flex items-start p-3 bg-gray-50 rounded-lg cursor-pointer hover:bg-gray-100">
                            <input type="checkbox" name="bill_allocations[${bill.id}][bill_id]" value="${bill.id}"
                                class="mt-1 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500 bill-checkbox"
                                data-amount-due="${parseFloat(bill.amount_due).toFixed(2)}">
                            <div class="ml-3 flex-1">
                                <div class="flex justify-between">
                                    <span class="font-medium">${bill.bill_number}</span>
                                    <span class="text-red-600 font-medium" data-due
                                        title="Outstanding balance">$${parseFloat(bill.amount_due).toFixed(2)}</span>
                                </div>
                                <div class="text-sm text-gray-500">
                                    Due: ${new Date(bill.due_date).toLocaleDateString('en-AU')}
                                    · Bill total: $${parseFloat(bill.total).toFixed(2)}
                                </div>
                            </div>
                            <div class="ml-3 w-32">
                                <input type="number" name="bill_allocations[${bill.id}][amount]"
                                    placeholder="Amount" step="0.01" min="0" disabled
                                    class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm allocation-amount">
                            </div>
                        </label>
                    `;
                    });
                    billsList.innerHTML = html;

                    // Default to 100% allocation of each nominated bill:
                    // ticking pre-fills the full outstanding balance, and the
                    // payment amount follows the sum of the allocations. The
                    // amount input starts disabled so unchecked rows submit
                    // nothing at all (no half-pairs for the server to reject).
                    document.querySelectorAll('.bill-checkbox').forEach(checkbox => {
                        checkbox.addEventListener('change', function() {
                            const row = this.closest('label');
                            const amountInput = row.querySelector('.allocation-amount');
                            if (this.checked) {
                                amountInput.disabled = false;
                                amountInput.value = this.dataset.amountDue;
                            } else {
                                amountInput.value = '';
                                amountInput.disabled = true;
                            }
                            syncPaymentAmount();
                        });
                    });

                    document.querySelectorAll('.allocation-amount').forEach(input => {
                        input.addEventListener('input', syncPaymentAmount);
                    });
                })
                .catch(error => {
                    console.error('Error loading bills:', error);
                    billsList.innerHTML = '<p class="text-red-500">Error loading bills. Please try again.</p>';
                });
        }

        // Keep the payment amount in step with the checked allocations (the
        // 100%-of-nominated default). Manually editing the payment amount
        // afterwards is still possible — it only re-syncs when an allocation
        // changes.
        function syncPaymentAmount() {
            let sum = 0;
            document.querySelectorAll('.bill-checkbox:checked').forEach(checkbox => {
                const row = checkbox.closest('label');
                const amount = parseFloat(row.querySelector('.allocation-amount')?.value) || 0;
                sum += amount;
            });
            const amountField = document.querySelector('input[name="amount"]');
            if (sum > 0) {
                amountField.value = sum.toFixed(2);
            }
        }

        loadSupplierBills();
    </script>
@endpush
