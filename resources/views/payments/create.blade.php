@extends('layouts.app')
@section('title', 'Record Payment')
@section('content')

    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-800">Record Payment</h1>
    </div>

    <form action="{{ route('payments.store') }}" method="POST" class="space-y-6">
        @csrf

        <div class="bg-white rounded-lg shadow p-6">
            <h2 class="text-lg font-semibold text-gray-800 mb-4">Payment Details</h2>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Client *</label>
                    <select name="client_id" id="clientSelect" required
                        class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 w-full">
                        <option value="">Select Client</option>
                        @foreach ($clients as $id => $name)
                            <option value="{{ $id }}"
                                {{ old('client_id', $selectedClient?->id) == $id ? 'selected' : '' }}>{{ $name }}
                            </option>
                        @endforeach
                    </select>
                    @error('client_id')
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
                <label class="block text-sm font-medium text-gray-700 mb-2">Allocate payment to invoices</label>
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
                    Select invoices to allocate payment to. Select a client first to see outstanding invoices.
                </p>
                <div id="invoicesList" class="space-y-2">
                    <!-- Invoices will be loaded dynamically -->
                </div>
            </div>
        </div>

        <div class="flex justify-end gap-4">
            <a href="{{ route('payments.index') }}"
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
                    loadClientInvoices();
                } else {
                    manualSection.classList.add('hidden');
                }
            });
        });

        document.getElementById('clientSelect').addEventListener('change', function() {
            if (document.querySelector('input[name="allocate_type"]:checked').value === 'manual') {
                loadClientInvoices();
            }
        });

        function loadClientInvoices() {
            const clientId = document.getElementById('clientSelect').value;
            const invoicesList = document.getElementById('invoicesList');

            if (!clientId) {
                invoicesList.innerHTML = '<p class="text-gray-500">Select a client to see outstanding invoices.</p>';
                return;
            }

            // Fetch outstanding invoices for this client
            fetch(`/payments/client-invoices/${clientId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.length === 0) {
                        invoicesList.innerHTML =
                        '<p class="text-gray-500">No outstanding invoices for this client.</p>';
                        return;
                    }

                    let html = '';
                    data.forEach(invoice => {
                        html += `
                        <label class="flex items-start p-3 bg-gray-50 rounded-lg cursor-pointer hover:bg-gray-100">
                            <input type="checkbox" name="invoice_allocations[][invoice_id]" value="${invoice.id}"
                                class="mt-1 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500 invoice-checkbox"
                                data-amount-due="${parseFloat(invoice.amount_due).toFixed(2)}">
                            <div class="ml-3 flex-1">
                                <div class="flex justify-between">
                                    <span class="font-medium">${invoice.invoice_number}</span>
                                    <span class="text-red-600 font-medium" data-due
                                        title="Outstanding balance">$${parseFloat(invoice.amount_due).toFixed(2)}</span>
                                </div>
                                <div class="text-sm text-gray-500">
                                    Due: ${new Date(invoice.due_date).toLocaleDateString('en-AU')}
                                    · Invoice total: $${parseFloat(invoice.total).toFixed(2)}
                                </div>
                            </div>
                            <div class="ml-3 w-32">
                                <input type="number" name="invoice_allocations[][amount]"
                                    placeholder="Amount" step="0.01" min="0"
                                    class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm allocation-amount">
                            </div>
                        </label>
                    `;
                    });
                    invoicesList.innerHTML = html;

                    // Default to 100% allocation of each nominated invoice:
                    // ticking pre-fills the full outstanding balance, and the
                    // payment amount follows the sum of the allocations.
                    document.querySelectorAll('.invoice-checkbox').forEach(checkbox => {
                        checkbox.addEventListener('change', function() {
                            const row = this.closest('label');
                            const amountInput = row.querySelector('.allocation-amount');
                            if (this.checked) {
                                amountInput.value = this.dataset.amountDue;
                            } else {
                                amountInput.value = '';
                            }
                            syncPaymentAmount();
                        });
                    });

                    document.querySelectorAll('.allocation-amount').forEach(input => {
                        input.addEventListener('input', syncPaymentAmount);
                    });
                })
                .catch(error => {
                    console.error('Error loading invoices:', error);
                    invoicesList.innerHTML = '<p class="text-red-500">Error loading invoices. Please try again.</p>';
                });
        }

        // Keep the payment amount in step with the checked allocations (the
        // 100%-of-nominated default). Manually editing the payment amount
        // afterwards is still possible — it only re-syncs when an allocation
        // changes.
        function syncPaymentAmount() {
            let sum = 0;
            document.querySelectorAll('.invoice-checkbox:checked').forEach(checkbox => {
                const row = checkbox.closest('label');
                const amount = parseFloat(row.querySelector('.allocation-amount')?.value) || 0;
                sum += amount;
            });
            const amountField = document.querySelector('input[name="amount"]');
            if (sum > 0) {
                amountField.value = sum.toFixed(2);
            }
        }
    </script>
@endpush
