@extends('layouts.app')
@section('title', 'Bill ' . $bill->bill_number)
@section('content')

    <div class="mb-6 flex justify-between items-center">
        <div>
            <h1 class="text-2xl font-bold text-gray-800">Bill {{ $bill->bill_number }}</h1>
            <p class="text-gray-600">
                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full
                    @if($bill->status === 'paid') bg-green-100 text-green-800
                    @elseif($bill->status === 'overdue') bg-red-100 text-red-800
                    @elseif($bill->status === 'partially_paid') bg-yellow-100 text-yellow-800
                    @else bg-gray-100 text-gray-800
                    @endif">
                    {{ ucfirst(str_replace('_', ' ', $bill->status)) }}
                </span>
            </p>
        </div>
        <div class="flex gap-2">
            @if($bill->status === 'draft')
                <form action="{{ route('bills.open', $bill) }}" method="POST" class="inline">
                    @csrf
                    <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg">
                        Mark as Open
                    </button>
                </form>
            @endif
            @if($bill->canBeEdited())
                <a href="{{ route('bills.edit', $bill) }}" class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg">
                    Edit
                </a>
            @endif
            @if($bill->canBeCancelled())
                <form action="{{ route('bills.cancel', $bill) }}" method="POST" class="inline"
                    onsubmit="return confirm('Are you sure you want to cancel this bill?');">
                    @csrf
                    <button type="submit" class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-lg">
                        Cancel
                    </button>
                </form>
            @endif
            @php
                $confirmText = $bill->amount_paid > 0
                    ? 'Delete bill ' . $bill->bill_number . '? Its payments ($' . number_format($bill->amount_paid, 2)
                        . ' paid) will be voided and their ledger entries reversed.'
                    : 'Delete bill ' . $bill->bill_number . '? This cannot be undone.';
            @endphp
            <form action="{{ route('bills.destroy', $bill) }}" method="POST" class="inline"
                onsubmit="return confirm(@js($confirmText));">
                @csrf
                @method('DELETE')
                <button type="submit" class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-lg">
                    Delete
                </button>
            </form>
        </div>
    </div>

    @if(session('success'))
        <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4">
            {{ session('success') }}
        </div>
    @endif
    @if(session('error'))
        <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
            {{ session('error') }}
        </div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Main Bill Details -->
        <div class="lg:col-span-2 space-y-6">
            <!-- Bill Details Card -->
            <div class="bg-white rounded-lg shadow p-6">
                <div class="flex justify-between items-start mb-6">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-800">Bill Details</h2>
                        <p class="text-sm text-gray-600">Bill Date: {{ $bill->bill_date->format('d M Y') }}</p>
                        <p class="text-sm text-gray-600">Due Date: {{ $bill->due_date?->format('d M Y') }}
                            @if($bill->is_overdue)
                                <span class="text-red-600 font-medium">({{ abs($bill->days_until_due) }} days overdue)</span>
                            @endif
                        </p>
                        @if($bill->reference)
                            <p class="text-sm text-gray-600">Supplier Ref: {{ $bill->reference }}</p>
                        @endif
                    </div>
                    <div class="text-right">
                        <p class="text-sm text-gray-600">Created by</p>
                        <p class="font-medium">{{ $bill->creator?->name ?? 'System' }}</p>
                    </div>
                </div>

                <table class="min-w-full">
                    <thead>
                        <tr class="border-b">
                            <th class="text-left py-2 text-xs font-medium text-gray-500 uppercase">Description</th>
                            <th class="text-right py-2 text-xs font-medium text-gray-500 uppercase">Qty</th>
                            <th class="text-right py-2 text-xs font-medium text-gray-500 uppercase">Unit Price (as paid)</th>
                            <th class="text-right py-2 text-xs font-medium text-gray-500 uppercase">GST</th>
                            <th class="text-left py-2 text-xs font-medium text-gray-500 uppercase">Account</th>
                            <th class="text-right py-2 text-xs font-medium text-gray-500 uppercase">Total</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        @foreach($bill->items as $item)
                            <tr>
                                <td class="py-3">
                                    {{ $item->description }}
                                    @if($item->is_prepaid)
                                        <span class="block text-xs text-indigo-600"
                                            title="Held as a prepaid asset and expensed monthly over the service period">
                                            Prepaid — service period {{ optional($item->service_start)->format('d/m/Y') }} – {{ optional($item->service_end)->format('d/m/Y') }}
                                        </span>
                                    @endif
                                </td>
                                <td class="py-3 text-right">{{ number_format($item->quantity, 2) }}</td>
                                <td class="py-3 text-right">${{ number_format($item->unit_price, 2) }}</td>
                                <td class="py-3 text-right">
                                    @if($item->is_gst_free)
                                        <span class="text-gray-400" title="GST-free by regulation">Free</span>
                                    @elseif($item->gst_added)
                                        <span title="Ex-GST amount; GST was added on top">+ {{ $item->tax_rate }}%</span>
                                    @else
                                        <span title="Amount is GST-inclusive; this portion was back-calculated">Incl. {{ $item->tax_rate }}%</span>
                                    @endif
                                </td>
                                <td class="py-3 text-sm text-gray-600">
                                    {{ $item->expenseAccount?->code ? $item->expenseAccount->code . ' — ' . $item->expenseAccount->name : '—' }}
                                </td>
                                <td class="py-3 text-right font-medium">${{ number_format($item->total, 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>

                <div class="mt-4 border-t pt-4">
                    <div class="flex justify-between text-sm mb-1">
                        <span class="text-gray-600">Subtotal</span>
                        <span>${{ number_format($bill->subtotal, 2) }}</span>
                    </div>
                    <div class="flex justify-between text-sm mb-1">
                        <span class="text-gray-600">GST</span>
                        <span>${{ number_format($bill->tax_amount, 2) }}</span>
                    </div>
                    @if($bill->discount_amount > 0)
                        <div class="flex justify-between text-sm mb-1">
                            <span class="text-gray-600">Discount</span>
                            <span>-${{ number_format($bill->discount_amount, 2) }}</span>
                        </div>
                    @endif
                    <div class="flex justify-between text-lg font-bold border-t pt-2">
                        <span>Total</span>
                        <span>${{ number_format($bill->total, 2) }}</span>
                    </div>
                </div>
            </div>

            <!-- Payments -->
            <div class="bg-white rounded-lg shadow p-6">
                <h2 class="text-lg font-semibold text-gray-800 mb-4">Payments</h2>

                @if($bill->allocations->isNotEmpty())
                    <table class="min-w-full mb-4">
                        <thead>
                            <tr class="border-b">
                                <th class="text-left py-2 text-xs font-medium text-gray-500 uppercase">Payment #</th>
                                <th class="text-left py-2 text-xs font-medium text-gray-500 uppercase">Date</th>
                                <th class="text-right py-2 text-xs font-medium text-gray-500 uppercase">Amount</th>
                                <th class="text-right py-2 text-xs font-medium text-gray-500 uppercase">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            @foreach($bill->allocations as $allocation)
                                <tr>
                                    <td class="py-2">
                                        <a href="{{ route('bill-payments.show', $allocation->billPayment) }}" class="text-indigo-600 hover:text-indigo-900">
                                            {{ $allocation->billPayment->payment_number }}
                                        </a>
                                    </td>
                                    <td class="py-2">{{ $allocation->billPayment->payment_date->format('d M Y') }}</td>
                                    <td class="py-2 text-right">${{ number_format($allocation->amount, 2) }}</td>
                                    <td class="py-2 text-right">
                                        @if($allocation->billPayment->status !== \App\Models\BillPayment::STATUS_VOID)
                                            @php
                                                $isShared = $allocation->billPayment->allocations->count() > 1;
                                                $unapplyText = $isShared
                                                    ? 'Remove this bill\'s share of payment ' . $allocation->billPayment->payment_number
                                                        . '? Only this bill\'s share of the ledger entry is reversed; the payment stays active for its other bills.'
                                                    : 'Remove payment ' . $allocation->billPayment->payment_number
                                                        . '? The payment will be voided and its ledger entry fully reversed, making this bill editable again.';
                                            @endphp
                                            <form action="{{ route('bills.unapplyPayment', [$bill, $allocation->billPayment]) }}" method="POST" class="inline"
                                                onsubmit="return confirm(@js($unapplyText));">
                                                @csrf
                                                <button type="submit" class="text-red-600 hover:text-red-900 text-sm">
                                                    {{ $isShared ? 'Unapply' : 'Remove' }}
                                                </button>
                                            </form>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @else
                    <p class="text-gray-500 mb-4">No payments recorded yet.</p>
                @endif

                <div class="flex justify-between items-center p-4 bg-gray-50 rounded-lg">
                    <div>
                        <p class="text-sm text-gray-600">Amount Paid</p>
                        <p class="text-xl font-bold text-green-600">${{ number_format($bill->amount_paid, 2) }}</p>
                    </div>
                    <div class="text-right">
                        <p class="text-sm text-gray-600">Balance Due</p>
                        <p class="text-xl font-bold {{ $bill->amount_due > 0 ? 'text-red-600' : 'text-green-600' }}">
                            ${{ number_format($bill->amount_due, 2) }}
                        </p>
                    </div>
                </div>

                @if($bill->amount_due > 0 && !in_array($bill->status, ['draft', 'cancelled']))
                    <div class="mt-4 flex gap-2">
                        <button type="button" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg"
                            onclick="document.getElementById('paymentModal').classList.remove('hidden')">
                            Record Payment
                        </button>
                    </div>
                @endif
            </div>

            <!-- Notes -->
            @if($bill->notes)
                <div class="bg-white rounded-lg shadow p-6">
                    <h2 class="text-lg font-semibold text-gray-800 mb-2">Notes</h2>
                    <p class="text-gray-600">{{ $bill->notes }}</p>
                </div>
            @endif

            <!-- Documents -->
            <div class="bg-white rounded-lg shadow p-6">
                <div class="flex justify-between items-center mb-4">
                    <h2 class="text-lg font-semibold text-gray-800">Documents</h2>
                    @if($bill->canBeEdited())
                        <a href="{{ route('bills.edit', $bill) }}" class="text-sm text-indigo-600 hover:text-indigo-800">
                            Upload in Edit View →
                        </a>
                    @endif
                </div>

                @if($bill->documents->count() > 0)
                    <div class="border rounded-lg divide-y">
                        @foreach($bill->documents as $doc)
                            <div class="flex items-center justify-between p-3">
                                <div class="flex items-center">
                                    <svg class="h-5 w-5 text-gray-400 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"/>
                                    </svg>
                                    <span class="text-sm font-medium text-gray-900">{{ $doc->name }}</span>
                                </div>
                                <a href="{{ route('documents.download', $doc) }}" class="text-indigo-600 hover:text-indigo-900 text-sm">Download</a>
                            </div>
                        @endforeach
                    </div>
                @else
                    <p class="text-sm text-gray-500 text-center py-2">No documents attached</p>
                @endif
            </div>
        </div>

        <!-- Sidebar -->
        <div class="space-y-6">
            <!-- Supplier Info -->
            <div class="bg-white rounded-lg shadow p-6">
                <h2 class="text-lg font-semibold text-gray-800 mb-4">Supplier</h2>
                <p class="font-medium">{{ $bill->supplier->name }}</p>
                <p class="text-gray-600">{{ $bill->supplier->email }}</p>
                @if($bill->supplier->phone)
                    <p class="text-gray-600">{{ $bill->supplier->phone }}</p>
                @endif
                <a href="{{ route('suppliers.show', $bill->supplier) }}" class="text-indigo-600 hover:text-indigo-800 text-sm mt-2 inline-block">
                    View Supplier →
                </a>
            </div>

            <!-- Project Info -->
            @if($bill->project)
                <div class="bg-white rounded-lg shadow p-6">
                    <h2 class="text-lg font-semibold text-gray-800 mb-4">Project</h2>
                    <p class="font-medium">{{ $bill->project->name }}</p>
                    <a href="{{ route('projects.show', $bill->project) }}" class="text-indigo-600 hover:text-indigo-800 text-sm mt-2 inline-block">
                        View Project →
                    </a>
                </div>
            @endif

            <!-- Quick Actions -->
            <div class="bg-white rounded-lg shadow p-6">
                <h2 class="text-lg font-semibold text-gray-800 mb-4">Quick Actions</h2>
                <div class="space-y-2">
                    <a href="{{ route('bills.index') }}" class="block text-indigo-600 hover:text-indigo-800">
                        ← Back to Bills
                    </a>
                    @if($bill->amount_due > 0 && !in_array($bill->status, ['draft', 'cancelled']))
                        <a href="{{ route('bill-payments.create', ['supplier_id' => $bill->supplier_id]) }}"
                            class="block text-indigo-600 hover:text-indigo-800">
                            Pay via Supplier Payment →
                        </a>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <!-- Payment Modal -->
    <div id="paymentModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
        <div class="bg-white rounded-lg p-6 w-full max-w-md">
            <h3 class="text-lg font-semibold mb-4">Record Payment for Bill #{{ $bill->bill_number }}</h3>
            <p class="text-gray-600 mb-4">Balance Due: <strong>${{ number_format($bill->amount_due, 2) }}</strong></p>

            <form action="{{ route('bills.recordPayment', $bill) }}" method="POST">
                @csrf
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Amount *</label>
                        <input type="number" name="amount" value="{{ $bill->amount_due }}"
                            step="0.01" min="0.01" max="{{ $bill->amount_due }}" required
                            class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 w-full">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Payment Date *</label>
                        <input type="date" name="payment_date" value="{{ now()->toDateString() }}" required
                            class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 w-full">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Payment Method *</label>
                        <select name="payment_method" required
                            class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 w-full">
                            @foreach ($paymentMethods as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Reference</label>
                        <input type="text" name="reference"
                            class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 w-full"
                            placeholder="Transaction ID, Cheque #, etc.">
                    </div>
                </div>
                <div class="flex justify-end gap-2 mt-6">
                    <button type="button" onclick="document.getElementById('paymentModal').classList.add('hidden')"
                        class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-4 py-2 rounded-lg">
                        Cancel
                    </button>
                    <button type="submit" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg">
                        Record Payment
                    </button>
                </div>
            </form>
        </div>
    </div>

@endsection
