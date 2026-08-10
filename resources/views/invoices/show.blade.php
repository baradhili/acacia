@extends('layouts.app')
@section('title', 'Invoice ' . $invoice->invoice_number)
@section('content')

    <div class="mb-6 flex justify-between items-center">
        <div>
            <h1 class="text-2xl font-bold text-gray-800">Invoice {{ $invoice->invoice_number }}</h1>
            <p class="text-gray-600">
                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full
                    @if($invoice->status === 'paid') bg-green-100 text-green-800
                    @elseif($invoice->status === 'overdue') bg-red-100 text-red-800
                    @elseif($invoice->status === 'partially_paid') bg-yellow-100 text-yellow-800
                    @else bg-gray-100 text-gray-800
                    @endif">
                    {{ ucfirst(str_replace('_', ' ', $invoice->status)) }}
                </span>
            </p>
        </div>
        <div class="flex gap-2">
            @if($invoice->status === 'draft')
                <form action="{{ route('invoices.send', $invoice) }}" method="POST" class="inline">
                    @csrf
                    <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg">
                        Mark as Sent
                    </button>
                </form>
                <a href="{{ route('invoices.edit', $invoice) }}" class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg">
                    Edit
                </a>
            @endif
            <a href="{{ route('invoices.downloadPdf', $invoice) }}" class="bg-gray-600 hover:bg-gray-700 text-white px-4 py-2 rounded-lg">
                Download PDF
            </a>
            @if($invoice->status !== 'cancelled' && $invoice->status !== 'draft')
                <a href="{{ route('invoices.apply-credit', $invoice) }}" class="bg-orange-600 hover:bg-orange-700 text-white px-4 py-2 rounded-lg">
                    Apply Credit
                </a>
                <a href="{{ route('credit-notes.create-from-invoice', $invoice) }}" class="bg-orange-600 hover:bg-orange-700 text-white px-4 py-2 rounded-lg">
                    Create Credit Note
                </a>
            @endif
            @if($invoice->canBeCancelled())
                <form action="{{ route('invoices.cancel', $invoice) }}" method="POST" class="inline"
                    onsubmit="return confirm('Are you sure you want to cancel this invoice?');">
                    @csrf
                    <button type="submit" class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-lg">
                        Cancel
                    </button>
                </form>
            @endif
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
        <!-- Main Invoice Details -->
        <div class="lg:col-span-2 space-y-6">
            <!-- Invoice Details Card -->
            <div class="bg-white rounded-lg shadow p-6">
                <div class="flex justify-between items-start mb-6">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-800">Invoice Details</h2>
                        <p class="text-sm text-gray-600">Issue Date: {{ $invoice->issue_date->format('d M Y') }}</p>
                        <p class="text-sm text-gray-600">Due Date: {{ $invoice->due_date?->format('d M Y') }}
                            @if($invoice->is_overdue)
                                <span class="text-red-600 font-medium">({{ abs($invoice->days_until_due) }} days overdue)</span>
                            @endif
                        </p>
                    </div>
                    <div class="text-right">
                        <p class="text-sm text-gray-600">Created by</p>
                        <p class="font-medium">{{ $invoice->creator?->name ?? 'System' }}</p>
                    </div>
                </div>

                <table class="min-w-full">
                    <thead>
                        <tr class="border-b">
                            <th class="text-left py-2 text-xs font-medium text-gray-500 uppercase">Description</th>
                            <th class="text-right py-2 text-xs font-medium text-gray-500 uppercase">Qty</th>
                            <th class="text-right py-2 text-xs font-medium text-gray-500 uppercase">Unit Price</th>
                            <th class="text-right py-2 text-xs font-medium text-gray-500 uppercase">Tax</th>
                            <th class="text-right py-2 text-xs font-medium text-gray-500 uppercase">Total</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        @foreach($invoice->items as $item)
                            <tr>
                                <td class="py-3">{{ $item->description }}</td>
                                <td class="py-3 text-right">{{ number_format($item->quantity, 2) }}</td>
                                <td class="py-3 text-right">${{ number_format($item->unit_price, 2) }}</td>
                                <td class="py-3 text-right">{{ $item->tax_rate }}%</td>
                                <td class="py-3 text-right font-medium">${{ number_format($item->total, 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>

                <div class="mt-4 border-t pt-4">
                    <div class="flex justify-between text-sm mb-1">
                        <span class="text-gray-600">Subtotal</span>
                        <span>${{ number_format($invoice->subtotal, 2) }}</span>
                    </div>
                    <div class="flex justify-between text-sm mb-1">
                        <span class="text-gray-600">Tax (GST 10%)</span>
                        <span>${{ number_format($invoice->tax_amount, 2) }}</span>
                    </div>
                    @if($invoice->discount_amount > 0)
                        <div class="flex justify-between text-sm mb-1">
                            <span class="text-gray-600">Discount</span>
                            <span>-${{ number_format($invoice->discount_amount, 2) }}</span>
                        </div>
                    @endif
                    <div class="flex justify-between text-lg font-bold border-t pt-2">
                        <span>Total</span>
                        <span>${{ number_format($invoice->total, 2) }}</span>
                    </div>
                </div>
            </div>

            <!-- Payments -->
            <div class="bg-white rounded-lg shadow p-6">
                <h2 class="text-lg font-semibold text-gray-800 mb-4">Payments</h2>
                
                @if($invoice->payments->isNotEmpty())
                    <table class="min-w-full mb-4">
                        <thead>
                            <tr class="border-b">
                                <th class="text-left py-2 text-xs font-medium text-gray-500 uppercase">Payment #</th>
                                <th class="text-left py-2 text-xs font-medium text-gray-500 uppercase">Date</th>
                                <th class="text-right py-2 text-xs font-medium text-gray-500 uppercase">Amount</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            @foreach($invoice->payments as $payment)
                                <tr>
                                    <td class="py-2">{{ $payment->payment_number }}</td>
                                    <td class="py-2">{{ $payment->payment_date->format('d M Y') }}</td>
                                    <td class="py-2 text-right">${{ number_format($payment->amount, 2) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @else
                    <p class="text-gray-500 mb-4">No payments received yet.</p>
                @endif

                <div class="flex justify-between items-center p-4 bg-gray-50 rounded-lg">
                    <div>
                        <p class="text-sm text-gray-600">Amount Paid</p>
                        <p class="text-xl font-bold text-green-600">${{ number_format($invoice->amount_paid, 2) }}</p>
                    </div>
                    <div class="text-right">
                        <p class="text-sm text-gray-600">Balance Due</p>
                        <p class="text-xl font-bold {{ $invoice->amount_due > 0 ? 'text-red-600' : 'text-green-600' }}">
                            ${{ number_format($invoice->amount_due, 2) }}
                        </p>
                    </div>
                </div>

                @if($invoice->amount_due > 0 && $invoice->status !== 'cancelled')
                    <div class="mt-4 flex gap-2">
                        <button type="button" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg"
                            onclick="document.getElementById('paymentModal').classList.remove('hidden')">
                            Mark as Paid
                        </button>
                    </div>
                @endif
            </div>

            <!-- Credit Notes -->
            @if($invoice->creditNotes->isNotEmpty())
                <div class="bg-white rounded-lg shadow p-6">
                    <h2 class="text-lg font-semibold text-gray-800 mb-4">Credit Notes</h2>
                    <table class="min-w-full">
                        <thead>
                            <tr class="border-b">
                                <th class="text-left py-2 text-xs font-medium text-gray-500 uppercase">Credit Note #</th>
                                <th class="text-left py-2 text-xs font-medium text-gray-500 uppercase">Date</th>
                                <th class="text-right py-2 text-xs font-medium text-gray-500 uppercase">Amount</th>
                                <th class="text-left py-2 text-xs font-medium text-gray-500 uppercase">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            @foreach($invoice->creditNotes as $cn)
                                <tr>
                                    <td class="py-2">
                                        <a href="{{ route('credit-notes.show', $cn) }}" class="text-indigo-600 hover:text-indigo-800">
                                            {{ $cn->credit_note_number }}
                                        </a>
                                    </td>
                                    <td class="py-2">{{ $cn->issue_date->format('d M Y') }}</td>
                                    <td class="py-2 text-right text-orange-600">-${{ number_format($cn->total, 2) }}</td>
                                    <td class="py-2">
                                        <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full
                                            @if($cn->status === 'applied') bg-green-100 text-green-800
                                            @elseif($cn->status === 'void') bg-gray-100 text-gray-500
                                            @else bg-blue-100 text-blue-800 @endif">
                                            {{ ucfirst($cn->status) }}
                                        </span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            <!-- Notes -->
            @if($invoice->notes)
                <div class="bg-white rounded-lg shadow p-6">
                    <h2 class="text-lg font-semibold text-gray-800 mb-2">Notes</h2>
                    <p class="text-gray-600">{{ $invoice->notes }}</p>
                </div>
            @endif

            <!-- Documents -->
            <div class="bg-white rounded-lg shadow p-6">
                <div class="flex justify-between items-center mb-4">
                    <h2 class="text-lg font-semibold text-gray-800">Documents</h2>
                    <a href="#attach-document" name="Attach Document" class="text-sm text-indigo-600 hover:text-indigo-800">Attach Document</a>
                </div>

                <!-- Upload Form -->
                <div id="attach-document" class="border rounded-lg p-4 mb-4 bg-gray-50">
                    <form action="{{ route('documents.store') }}" method="POST" enctype="multipart/form-data" class="space-y-3">
                        @csrf
                        <input type="hidden" name="documentable_type" value="App\Models\Invoice">
                        <input type="hidden" name="documentable_id" value="{{ $invoice->id }}">
                        <div>
                            <label class="block text-xs font-medium text-gray-700 mb-1">File</label>
                            <input type="file" name="file" required class="w-full text-sm">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-700 mb-1">Document Name</label>
                            <input type="text" name="name" placeholder="Enter document name"
                                class="rounded-md border-gray-300 shadow-sm w-full text-sm">
                        </div>
                        <button type="submit" name="Upload" class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg text-sm">Upload</button>
                    </form>
                </div>

                <!-- Document List -->
                @if($invoice->documents->count() > 0)
                    <div class="border rounded-lg divide-y">
                        @foreach($invoice->documents as $doc)
                            <div class="flex items-center justify-between p-3">
                                <div class="flex items-center">
                                    <svg class="h-5 w-5 text-gray-400 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"/>
                                    </svg>
                                    <span class="text-sm font-medium text-gray-900">{{ $doc->name }}</span>
                                </div>
                                <div class="flex gap-3">
                                    <a href="{{ route('documents.download', $doc) }}" class="text-indigo-600 hover:text-indigo-900 text-sm">Download</a>
                                    <form action="{{ route('documents.destroy', $doc) }}" method="POST" class="inline">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="text-red-600 hover:text-red-900 text-sm">Delete</button>
                                    </form>
                                </div>
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
            <!-- Client Info -->
            <div class="bg-white rounded-lg shadow p-6">
                <h2 class="text-lg font-semibold text-gray-800 mb-4">Client</h2>
                <p class="font-medium">{{ $invoice->client->name }}</p>
                <p class="text-gray-600">{{ $invoice->client->email }}</p>
                @if($invoice->client->phone)
                    <p class="text-gray-600">{{ $invoice->client->phone }}</p>
                @endif
                <a href="{{ route('clients.show', $invoice->client) }}" class="text-indigo-600 hover:text-indigo-800 text-sm mt-2 inline-block">
                    View Client →
                </a>
            </div>

            <!-- Project Info -->
            @if($invoice->project)
                <div class="bg-white rounded-lg shadow p-6">
                    <h2 class="text-lg font-semibold text-gray-800 mb-4">Project</h2>
                    <p class="font-medium">{{ $invoice->project->name }}</p>
                    <a href="{{ route('projects.show', $invoice->project) }}" class="text-indigo-600 hover:text-indigo-800 text-sm mt-2 inline-block">
                        View Project →
                    </a>
                </div>
            @endif

            <!-- Quick Actions -->
            <div class="bg-white rounded-lg shadow p-6">
                <h2 class="text-lg font-semibold text-gray-800 mb-4">Quick Actions</h2>
                <div class="space-y-2">
                    <a href="{{ route('invoices.index') }}" class="block text-indigo-600 hover:text-indigo-800">
                        ← Back to Invoices
                    </a>
                    @if($invoice->status === 'draft')
                        <form action="{{ route('invoices.send', $invoice) }}" method="POST">
                            @csrf
                            <button type="submit" class="text-blue-600 hover:text-blue-800">
                                Send Invoice
                            </button>
                        </form>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <!-- Payment Modal -->
    <div id="paymentModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
        <div class="bg-white rounded-lg p-6 w-full max-w-md">
            <h3 class="text-lg font-semibold mb-4">Record Payment for Invoice #{{ $invoice->invoice_number }}</h3>
            <p class="text-gray-600 mb-4">Amount Due: <strong>${{ number_format($invoice->amount_due, 2) }}</strong></p>
            
            <form action="{{ route('invoices.recordPayment', $invoice) }}" method="POST">
                @csrf
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Amount *</label>
                        <input type="number" name="amount" value="{{ $invoice->amount_due }}" 
                            step="0.01" min="0.01" max="{{ $invoice->amount_due }}" required
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
                            <option value="bank_transfer">Bank Transfer</option>
                            <option value="credit_card">Credit Card</option>
                            <option value="cash">Cash</option>
                            <option value="cheque">Cheque</option>
                            <option value="other">Other</option>
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
                        Save Payment
                    </button>
                </div>
            </form>
        </div>
    </div>

@push('scripts')
<script>
// No document delete handling on show view - delete only available in edit view
</script>
@endpush

@endsection
