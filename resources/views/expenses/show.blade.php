@extends('layouts.app')

@section('title', 'Expense: ' . ($expense->reference ?? $expense->id))

@section('header')
    <div class="flex items-center justify-between">
        <h2 class="text-xl font-semibold text-gray-800">
            Expense {{ $expense->reference ? "#{$expense->reference}" : "#{$expense->id}" }}
        </h2>
        <div class="flex items-center gap-2">
            @if($expense->status === 'draft')
                <form action="{{ route('expenses.submit', $expense) }}" method="POST" class="inline">
                    @csrf
                    <button type="submit" class="px-4 py-2 bg-yellow-600 text-white rounded-lg hover:bg-yellow-700">
                        Submit for Approval
                    </button>
                </form>
            @endif
            @if($expense->status === 'submitted')
                <form action="{{ route('expenses.approve', $expense) }}" method="POST" class="inline">
                    @csrf
                    <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                        Approve
                    </button>
                </form>
            @endif
            @if($expense->canBePaid())
                <button type="button" onclick="document.getElementById('payModal').classList.remove('hidden')"
                    class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700">
                    Mark as Paid
                </button>
            @endif
            @if($expense->canBeEdited())
                <a href="{{ route('expenses.edit', $expense) }}" class="px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700">
                    Edit
                </a>
            @endif
            @if($expense->canBeDeleted())
                <form action="{{ route('expenses.destroy', $expense) }}" method="POST" class="inline"
                    onsubmit="return confirm('Are you sure you want to delete this expense?')">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700">
                        Delete
                    </button>
                </form>
            @endif
            @if(!in_array($expense->status, ['paid', 'cancelled']))
                <form action="{{ route('expenses.cancel', $expense) }}" method="POST" class="inline">
                    @csrf
                    <button type="submit" class="px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700">
                        Cancel
                    </button>
                </form>
            @endif
        </div>
    </div>
@endsection

@push('styles')
<style>
    .document-upload-area {
        border: 2px dashed #d1d5db;
        border-radius: 0.5rem;
        padding: 2rem;
        text-align: center;
        transition: all 0.2s;
    }
    .document-upload-area:hover {
        border-color: #6366f1;
        background-color: #f9fafb;
    }
    .document-list-item {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 0.75rem;
        border-bottom: 1px solid #e5e7eb;
    }
    .document-list-item:last-child {
        border-bottom: none;
    }
</style>
@endpush

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function() {
    const uploadArea = document.getElementById('documentUploadArea');
    const fileInput = document.getElementById('documentFile');
    const form = document.getElementById('documentUploadForm');
    
    if (uploadArea && fileInput) {
        uploadArea.addEventListener('click', () => fileInput.click());
        uploadArea.addEventListener('dragover', (e) => {
            e.preventDefault();
            uploadArea.classList.add('border-indigo-500', 'bg-indigo-50');
        });
        uploadArea.addEventListener('dragleave', () => {
            uploadArea.classList.remove('border-indigo-500', 'bg-indigo-50');
        });
        uploadArea.addEventListener('drop', (e) => {
            e.preventDefault();
            uploadArea.classList.remove('border-indigo-500', 'bg-indigo-50');
            if (e.dataTransfer.files.length) {
                fileInput.files = e.dataTransfer.files;
                if (form) form.submit();
            }
        });
        fileInput.addEventListener('change', () => {
            if (fileInput.files.length) {
                if (form) form.submit();
            }
        });
    }
});
</script>
@endpush

@section('content')
    <!-- Pay Modal -->
    <div id="payModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
        <div class="bg-white rounded-lg p-6 max-w-md w-full mx-4">
            <h3 class="text-lg font-semibold mb-4">Mark Expense as Paid</h3>
            <form action="{{ route('expenses.pay', $expense) }}" method="POST">
                @csrf
                <div class="mb-4">
                    <label for="payment_method" class="block text-sm font-medium text-gray-700">Payment Method *</label>
                    <select name="payment_method" id="payment_method" required
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
                        <option value="bank_transfer">Bank Transfer</option>
                        <option value="credit_card">Credit Card</option>
                        <option value="cash">Cash</option>
                        <option value="cheque">Cheque</option>
                        <option value="other">Other</option>
                    </select>
                </div>
                <div class="flex justify-end gap-4">
                    <button type="button" onclick="document.getElementById('payModal').classList.add('hidden')"
                        class="px-4 py-2 text-gray-700 hover:text-gray-900">
                        Cancel
                    </button>
                    <button type="submit" class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700">
                        Confirm Payment
                    </button>
                </div>
            </form>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Main Details -->
        <div class="lg:col-span-2 space-y-6">
            <div class="bg-white rounded-lg shadow">
                <div class="p-6">
                    <div class="flex items-center justify-between mb-6">
                        <span class="px-3 py-1 text-sm font-semibold rounded-full 
                            @switch($expense->status)
                                @case('draft') bg-gray-100 text-gray-800 @break
                                @case('submitted') bg-yellow-100 text-yellow-800 @break
                                @case('approved') bg-blue-100 text-blue-800 @break
                                @case('paid') bg-green-100 text-green-800 @break
                                @case('cancelled') bg-red-100 text-red-800 @break
                            @endswitch">
                            {{ ucfirst($expense->status) }}
                        </span>
                        @if($expense->receipt_path)
                            <a href="{{ route('expenses.download-receipt', $expense) }}" 
                                class="text-indigo-600 hover:text-indigo-900 text-sm">
                                Download Receipt
                            </a>
                        @endif
                    </div>

                    <dl class="grid grid-cols-2 gap-x-4 gap-y-4">
                        <div>
                            <dt class="text-sm font-medium text-gray-500">Supplier</dt>
                            <dd class="mt-1 text-sm text-gray-900">{{ $expense->supplier->name ?? 'N/A' }}</dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-gray-500">Category</dt>
                            <dd class="mt-1 text-sm text-gray-900">{{ ucwords(str_replace('_', ' ', $expense->category)) }}</dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-gray-500">Expense Date</dt>
                            <dd class="mt-1 text-sm text-gray-900">{{ $expense->expense_date->format('d/m/Y') }}</dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-gray-500">Due Date</dt>
                            <dd class="mt-1 text-sm text-gray-900">{{ $expense->due_date ? $expense->due_date->format('d/m/Y') : 'N/A' }}</dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-gray-500">Reference</dt>
                            <dd class="mt-1 text-sm text-gray-900">{{ $expense->reference ?? 'N/A' }}</dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-gray-500">Amount (ex. GST)</dt>
                            <dd class="mt-1 text-sm text-gray-900">${{ number_format($expense->amount, 2) }}</dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-gray-500">GST</dt>
                            <dd class="mt-1 text-sm text-gray-900">${{ number_format($expense->tax_amount, 2) }}</dd>
                        </div>
                        <div class="col-span-2 border-t pt-4 mt-4">
                            <dt class="text-lg font-semibold text-gray-700">Total</dt>
                            <dd class="mt-1 text-2xl font-bold text-gray-900">${{ number_format($expense->total, 2) }}</dd>
                        </div>
                        @if($expense->description)
                            <div class="col-span-2">
                                <dt class="text-sm font-medium text-gray-500">Description</dt>
                                <dd class="mt-1 text-sm text-gray-900">{{ $expense->description }}</dd>
                            </div>
                        @endif
                        @if($expense->notes)
                            <div class="col-span-2">
                                <dt class="text-sm font-medium text-gray-500">Notes</dt>
                                <dd class="mt-1 text-sm text-gray-900">{{ $expense->notes }}</dd>
                            </div>
                        @endif
                    </dl>
                </div>
            </div>

            <!-- Documents Section -->
            <div class="bg-white rounded-lg shadow">
                <div class="p-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">Documents</h3>
                    
                    <!-- Upload Form -->
                    <form id="documentUploadForm" action="{{ route('documents.store') }}" method="POST" enctype="multipart/form-data" class="mb-4">
                        @csrf
                        <input type="hidden" name="documentable_type" value="App\Models\Expense">
                        <input type="hidden" name="documentable_id" value="{{ $expense->id }}">
                        <div id="documentUploadArea" class="document-upload-area cursor-pointer">
                            <svg class="mx-auto h-12 w-12 text-gray-400" stroke="currentColor" fill="none" viewBox="0 0 48 48">
                                <path d="M28 8H12a4 4 0 00-4 4v20m32-12v8m0 0v8a4 4 0 01-4 4H12a4 4 0 01-4-4v-4m32-4l-3.172-3.172a4 4 0 00-5.656 0L28 28M8 32l9.172-9.172a4 4 0 015.656 0L28 28m0 0l4 4m4-24h8m-4-4v8m-12 4h.02" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                            <p class="mt-1 text-sm text-gray-600">Drop files here or click to upload</p>
                            <p class="mt-1 text-xs text-gray-500">PDF, JPG, PNG, DOC up to 20MB</p>
                        </div>
                        <input type="file" name="file" id="documentFile" class="hidden" accept=".pdf,.jpg,.jpeg,.png,.gif,.doc,.docx,.xls,.xlsx,.txt,.zip,.rar">
                    </form>

                    <!-- Document List -->
                    @if($expense->documents->count() > 0)
                        <div class="border rounded-lg divide-y">
                            @foreach($expense->documents as $doc)
                                <div class="document-list-item">
                                    <div class="flex items-center">
                                        <svg class="h-5 w-5 text-gray-400 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"/>
                                        </svg>
                                        <div>
                                            <p class="text-sm font-medium text-gray-900">{{ $doc->name }}</p>
                                            <p class="text-xs text-gray-500">{{ number_format($doc->size / 1024, 1) }} KB</p>
                                        </div>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <a href="{{ route('documents.download', $doc) }}" class="text-indigo-600 hover:text-indigo-900 text-sm">Download</a>
                                        <form action="{{ route('documents.destroy', $doc) }}" method="POST" class="inline">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="text-red-600 hover:text-red-900 text-sm" onclick="return confirm('Delete this document?')">Delete</button>
                                        </form>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <p class="text-sm text-gray-500 text-center py-4">No documents attached</p>
                    @endif
                </div>
            </div>
        </div>

        <!-- Sidebar -->
        <div class="lg:col-span-1">
            @if($expense->status === 'paid')
                <div class="bg-green-50 rounded-lg shadow p-6 mb-6">
                    <h3 class="text-lg font-semibold text-green-800 mb-4">Payment Information</h3>
                    <dl class="space-y-2">
                        <div>
                            <dt class="text-sm font-medium text-green-700">Paid Date</dt>
                            <dd class="text-sm text-green-900">{{ $expense->paid_date?->format('d/m/Y') ?? 'N/A' }}</dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-green-700">Payment Method</dt>
                            <dd class="text-sm text-green-900">{{ ucwords(str_replace('_', ' ', $expense->payment_method ?? 'N/A')) }}</dd>
                        </div>
                        @if($expense->paidBy)
                            <div>
                                <dt class="text-sm font-medium text-green-700">Paid By</dt>
                                <dd class="text-sm text-green-900">{{ $expense->paidBy->name }}</dd>
                            </div>
                        @endif
                    </dl>
                </div>
            @endif

            <div class="bg-white rounded-lg shadow p-6">
                <h3 class="text-lg font-semibold text-gray-800 mb-4">Details</h3>
                <dl class="space-y-3">
                    <div>
                        <dt class="text-xs font-medium text-gray-500 uppercase">Created</dt>
                        <dd class="text-sm text-gray-900">{{ $expense->created_at->format('d/m/Y H:i') }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium text-gray-500 uppercase">Last Updated</dt>
                        <dd class="text-sm text-gray-900">{{ $expense->updated_at->format('d/m/Y H:i') }}</dd>
                    </div>
                    @if($expense->deleted_at)
                        <div>
                            <dt class="text-xs font-medium text-red-500 uppercase">Deleted</dt>
                            <dd class="text-sm text-red-700">{{ $expense->deleted_at->format('d/m/Y H:i') }}</dd>
                        </div>
                    @endif
                </dl>
            </div>
        </div>
    </div>
@endsection
