@extends('layouts.app')
@section('title', 'Supplier Payment ' . $billPayment->payment_number)
@section('content')

    <div class="mb-6 flex justify-between items-center">
        <div>
            <h1 class="text-2xl font-bold text-gray-800">Supplier Payment {{ $billPayment->payment_number }}</h1>
            <p class="text-gray-600">Paid on {{ $billPayment->payment_date->format('d M Y') }}</p>
        </div>
        <div class="flex gap-2">
            @if ($billPayment->unallocated_amount > 0)
                <button type="button" class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg"
                    onclick="document.getElementById('allocateModal').classList.remove('hidden')">
                    Allocate to Bill
                </button>
            @endif
            <a href="{{ route('bill-payments.edit', $billPayment) }}"
                class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg">
                Edit
            </a>
        </div>
    </div>

    @if (session('success'))
        <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4">
            {{ session('success') }}
        </div>
    @endif
    @if (session('error'))
        <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
            {{ session('error') }}
        </div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Payment Details -->
        <div class="lg:col-span-2 space-y-6">
            <!-- Allocations -->
            <div class="bg-white rounded-lg shadow p-6">
                <h2 class="text-lg font-semibold text-gray-800 mb-4">Bill Allocations</h2>

                @if ($billPayment->allocations->isNotEmpty())
                    <table class="min-w-full">
                        <thead>
                            <tr class="border-b">
                                <th class="text-left py-2 text-xs font-medium text-gray-500 uppercase">Bill</th>
                                <th class="text-left py-2 text-xs font-medium text-gray-500 uppercase">Due Date</th>
                                <th class="text-right py-2 text-xs font-medium text-gray-500 uppercase">Amount</th>
                                <th class="text-right py-2 text-xs font-medium text-gray-500 uppercase">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            @foreach ($billPayment->allocations as $allocation)
                                <tr>
                                    <td class="py-3">
                                        <a href="{{ route('bills.show', $allocation->bill) }}"
                                            class="text-indigo-600 hover:text-indigo-800">
                                            {{ $allocation->bill->bill_number }}
                                        </a>
                                    </td>
                                    <td class="py-3">{{ $allocation->bill->due_date?->format('d M Y') }}</td>
                                    <td class="py-3 text-right font-medium">${{ number_format($allocation->amount, 2) }}
                                    </td>
                                    <td class="py-3 text-right">
                                        <form
                                            action="{{ route('bill-payments.removeAllocation', [$billPayment, $allocation->bill]) }}"
                                            method="POST" class="inline">
                                            @csrf
                                            <button type="submit" class="text-red-600 hover:text-red-800 text-sm"
                                                onclick="return confirm('Remove this allocation?');">
                                                Remove
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr class="border-t-2">
                                <td colspan="2" class="py-3 font-bold">Total Allocated</td>
                                <td class="py-3 text-right font-bold text-green-600">
                                    ${{ number_format($billPayment->allocated_amount, 2) }}</td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                @else
                    <p class="text-gray-500">This payment has not been allocated to any bills yet.</p>
                @endif

                @if ($billPayment->unallocated_amount > 0)
                    <div class="mt-4 p-4 bg-yellow-50 rounded-lg">
                        <p class="text-sm text-yellow-800">
                            <strong>Unallocated amount:</strong> ${{ number_format($billPayment->unallocated_amount, 2) }}
                        </p>
                    </div>
                @endif
            </div>

            <!-- Notes -->
            @if ($billPayment->notes)
                <div class="bg-white rounded-lg shadow p-6">
                    <h2 class="text-lg font-semibold text-gray-800 mb-2">Notes</h2>
                    <p class="text-gray-600">{{ $billPayment->notes }}</p>
                </div>
            @endif

            <!-- Documents -->
            <div class="bg-white rounded-lg shadow p-6">
                <div class="flex justify-between items-center mb-4">
                    <h2 class="text-lg font-semibold text-gray-800">Documents</h2>
                    <a href="{{ route('bill-payments.edit', $billPayment) }}" class="text-sm text-indigo-600 hover:text-indigo-800">
                        Upload in Edit View →
                    </a>
                </div>

                @if ($billPayment->documents->count() > 0)
                    <div class="border rounded-lg divide-y">
                        @foreach ($billPayment->documents as $doc)
                            <div class="flex items-center justify-between p-3">
                                <div class="flex items-center">
                                    <svg class="h-5 w-5 text-gray-400 mr-2" fill="none" stroke="currentColor"
                                        viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z" />
                                    </svg>
                                    <span class="text-sm font-medium text-gray-900">{{ $doc->name }}</span>
                                </div>
                                <a href="{{ route('documents.download', $doc) }}"
                                    class="text-indigo-600 hover:text-indigo-900 text-sm">Download</a>
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
            <!-- Payment Info -->
            <div class="bg-white rounded-lg shadow p-6">
                <h2 class="text-lg font-semibold text-gray-800 mb-4">Payment Information</h2>
                <dl class="space-y-3">
                    <div>
                        <dt class="text-sm text-gray-500">Amount</dt>
                        <dd class="text-xl font-bold text-red-600">${{ number_format($billPayment->amount, 2) }}</dd>
                    </div>
                    <div>
                        <dt class="text-sm text-gray-500">Payment Date</dt>
                        <dd class="font-medium">{{ $billPayment->payment_date->format('d M Y') }}</dd>
                    </div>
                    <div>
                        <dt class="text-sm text-gray-500">Payment Method</dt>
                        <dd class="font-medium">{{ $billPayment->formatted_method }}</dd>
                    </div>
                    @if ($billPayment->reference)
                        <div>
                            <dt class="text-sm text-gray-500">Reference</dt>
                            <dd class="font-medium">{{ $billPayment->reference }}</dd>
                        </div>
                    @endif
                    <div>
                        <dt class="text-sm text-gray-500">Paid By</dt>
                        <dd class="font-medium">{{ $billPayment->payer?->name ?? 'System' }}</dd>
                    </div>
                    @if ($billPayment->is_posted_to_i_f_r_s)
                        <div>
                            <dt class="text-sm text-gray-500">Ledger</dt>
                            <dd class="font-medium text-green-600">Posted to IFRS (#{{ $billPayment->ifrs_payment_id }})</dd>
                        </div>
                    @endif
                </dl>
            </div>

            <!-- Supplier Info -->
            <div class="bg-white rounded-lg shadow p-6">
                <h2 class="text-lg font-semibold text-gray-800 mb-4">Supplier</h2>
                <p class="font-medium">{{ $billPayment->supplier->name }}</p>
                <p class="text-gray-600">{{ $billPayment->supplier->email }}</p>
                <a href="{{ route('suppliers.show', $billPayment->supplier) }}"
                    class="text-indigo-600 hover:text-indigo-800 text-sm mt-2 inline-block">
                    View Supplier →
                </a>
            </div>

            <!-- Quick Links -->
            <div class="bg-white rounded-lg shadow p-6">
                <h2 class="text-lg font-semibold text-gray-800 mb-4">Quick Actions</h2>
                <div class="space-y-2">
                    <a href="{{ route('bill-payments.index') }}" class="block text-indigo-600 hover:text-indigo-800">
                        ← Back to Supplier Payments
                    </a>
                    <a href="{{ route('bill-payments.create') }}" class="block text-indigo-600 hover:text-indigo-800">
                        + Record Another Payment
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Allocate Modal -->
    <div id="allocateModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
        <div class="bg-white rounded-lg p-6 w-full max-w-md">
            <h3 class="text-lg font-semibold mb-4">Allocate to Bill</h3>
            <p class="text-gray-600 mb-4">Unallocated:
                <strong>${{ number_format($billPayment->unallocated_amount, 2) }}</strong></p>

            <form action="{{ route('bill-payments.allocate', $billPayment) }}" method="POST">
                @csrf
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Bill *</label>
                        <select name="bill_id" required
                            class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 w-full">
                            <option value="">Select Bill</option>
                            @php
                                $outstandingBills = App\Models\Bill::with('allocations')
                                    ->where('supplier_id', $billPayment->supplier_id)
                                    ->whereIn('status', ['open', 'partially_paid', 'overdue'])
                                    ->get()
                                    ->filter(function ($bill) {
                                        return $bill->amount_due > 0;
                                    })
                                    ->sortBy('due_date')
                                    ->values();
                            @endphp
                            @foreach ($outstandingBills as $bill)
                                <option value="{{ $bill->id }}">
                                    {{ $bill->bill_number }} - ${{ number_format($bill->amount_due, 2) }} due
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Amount *</label>
                        <input type="number" name="amount" step="0.01" min="0.01"
                            max="{{ $billPayment->unallocated_amount }}" required
                            class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 w-full">
                    </div>
                </div>
                <div class="flex justify-end gap-2 mt-6">
                    <button type="button" onclick="document.getElementById('allocateModal').classList.add('hidden')"
                        class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-4 py-2 rounded-lg">
                        Cancel
                    </button>
                    <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg">
                        Allocate
                    </button>
                </div>
            </form>
        </div>
    </div>

@endsection
