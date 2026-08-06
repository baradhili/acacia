@extends('layouts.app')

@section('title', 'Edit Expense')

@section('header')
    <h2 class="text-xl font-semibold text-gray-800">Edit Expense</h2>
@endsection

@section('content')
    <div class="max-w-2xl mx-auto">
        <div class="bg-white rounded-lg shadow">
            <div class="p-6">
                <form method="POST" action="{{ route('expenses.update', $expense) }}" enctype="multipart/form-data">
                    @csrf
                    @method('PUT')

                    <div class="grid grid-cols-1 gap-6">
                        <!-- Supplier -->
                        <div>
                            <label for="supplier_id" class="block text-sm font-medium text-gray-700">Supplier *</label>
                            <select name="supplier_id" id="supplier_id" required
                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
                                <option value="">Select Supplier</option>
                                @foreach($suppliers as $id => $name)
                                    <option value="{{ $id }}" {{ old('supplier_id', $expense->supplier_id) == $id ? 'selected' : '' }}>
                                        {{ $name }}
                                    </option>
                                @endforeach
                            </select>
                            @error('supplier_id')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- Category -->
                        <div>
                            <label for="category" class="block text-sm font-medium text-gray-700">Category *</label>
                            <select name="category" id="category" required
                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
                                <option value="">Select Category</option>
                                @foreach($categories as $cat)
                                    <option value="{{ $cat }}" {{ old('category', $expense->category) == $cat ? 'selected' : '' }}>
                                        {{ ucwords(str_replace('_', ' ', $cat)) }}
                                    </option>
                                @endforeach
                            </select>
                            @error('category')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- Amount and Tax -->
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label for="amount" class="block text-sm font-medium text-gray-700">Amount (ex. GST) *</label>
                                <div class="mt-1 relative rounded-md shadow-sm">
                                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                        <span class="text-gray-500 sm:text-sm">$</span>
                                    </div>
                                    <input type="number" name="amount" id="amount" step="0.01" min="0.01" required
                                        value="{{ old('amount', $expense->amount) }}"
                                        class="pl-7 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
                                </div>
                                @error('amount')
                                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                                @enderror
                            </div>

                            <div>
                                <label for="tax_amount" class="block text-sm font-medium text-gray-700">GST Amount</label>
                                <div class="mt-1 relative rounded-md shadow-sm">
                                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                        <span class="text-gray-500 sm:text-sm">$</span>
                                    </div>
                                    <input type="number" name="tax_amount" id="tax_amount" step="0.01" min="0" 
                                        value="{{ old('tax_amount', $expense->tax_amount) }}"
                                        class="pl-7 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
                                </div>
                                @error('tax_amount')
                                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                                @enderror
                            </div>
                        </div>

                        <!-- Expense Date -->
                        <div>
                            <label for="expense_date" class="block text-sm font-medium text-gray-700">Expense Date *</label>
                            <input type="date" name="expense_date" id="expense_date" required
                                value="{{ old('expense_date', $expense->expense_date->format('Y-m-d')) }}"
                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
                            @error('expense_date')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- Due Date -->
                        <div>
                            <label for="due_date" class="block text-sm font-medium text-gray-700">Due Date</label>
                            <input type="date" name="due_date" id="due_date"
                                value="{{ old('due_date', $expense->due_date?->format('Y-m-d')) }}"
                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
                            @error('due_date')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- Reference -->
                        <div>
                            <label for="reference" class="block text-sm font-medium text-gray-700">Supplier Invoice #</label>
                            <input type="text" name="reference" id="reference" maxlength="255"
                                value="{{ old('reference', $expense->reference) }}"
                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
                            @error('reference')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- Description -->
                        <div>
                            <label for="description" class="block text-sm font-medium text-gray-700">Description</label>
                            <textarea name="description" id="description" rows="2" maxlength="1000"
                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">{{ old('description', $expense->description) }}</textarea>
                            @error('description')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- Notes -->
                        <div>
                            <label for="notes" class="block text-sm font-medium text-gray-700">Notes</label>
                            <textarea name="notes" id="notes" rows="2" maxlength="2000"
                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">{{ old('notes', $expense->notes) }}</textarea>
                            @error('notes')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- Receipt Upload with Drag & Drop -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Receipt</label>
                            @if($expense->receipt_path)
                                <p class="text-sm text-green-600 mb-2 flex items-center">
                                    <svg class="h-4 w-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                                    </svg>
                                    Current receipt uploaded
                                </p>
                            @endif
                            <div id="receipt-dropzone" 
                                class="mt-1 flex justify-center px-6 pt-5 pb-6 border-2 border-gray-300 border-dashed rounded-md hover:border-indigo-400 transition-colors cursor-pointer"
                                onclick="document.getElementById('receipt').click()">
                                <div class="space-y-1 text-center">
                                    <svg class="mx-auto h-12 w-12 text-gray-400" stroke="currentColor" fill="none" viewBox="0 0 48 48">
                                        <path d="M28 8H12a4 4 0 00-4 4v20m32-12v8m0 0v8a4 4 0 01-4 4H12a4 4 0 01-4-4v-4m32-4l-3.172-3.172a4 4 0 00-5.656 0L28 28M8 32l9.172-9.172a4 4 0 015.656 0L28 28m0 0l4 4m4-24h8m-4-4v8m-12 4h.02" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                                    </svg>
                                    <div class="flex text-sm text-gray-600">
                                        <label for="receipt" class="relative cursor-pointer rounded-md font-medium text-indigo-600 hover:text-indigo-500 focus-within:outline-none focus-within:ring-2 focus-within:ring-offset-2 focus-within:ring-indigo-500">
                                            <span>Upload a file</span>
                                            <input type="file" name="receipt" id="receipt" accept=".pdf,.jpg,.jpeg,.png" class="sr-only">
                                        </label>
                                        <p class="pl-1">or drag and drop</p>
                                    </div>
                                    <p class="text-xs text-gray-500">PDF, JPG, PNG up to 10MB</p>
                                </div>
                            </div>
                            <div id="receipt-preview" class="mt-2 hidden">
                                <div class="flex items-center justify-between p-2 bg-gray-50 rounded-md">
                                    <div class="flex items-center">
                                        <svg class="h-5 w-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                        </svg>
                                        <span id="receipt-filename" class="ml-2 text-sm text-gray-700"></span>
                                    </div>
                                    <button type="button" onclick="clearReceipt(); event.stopPropagation();" class="text-gray-400 hover:text-red-500">
                                        <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                        </svg>
                                    </button>
                                </div>
                            </div>
                            @error('receipt')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>

                    <div class="mt-6 flex items-center justify-end gap-4">
                        <a href="{{ route('expenses.show', $expense) }}" class="px-4 py-2 text-gray-700 hover:text-gray-900">
                            Cancel
                        </a>
                        <button type="submit" class="px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700">
                            Update Expense
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
<script>
    // Receipt drag and drop
    const receiptDropzone = document.getElementById('receipt-dropzone');
    const receiptInput = document.getElementById('receipt');
    const receiptPreview = document.getElementById('receipt-preview');
    const receiptFilename = document.getElementById('receipt-filename');

    ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
        receiptDropzone.addEventListener(eventName, preventDefaults, false);
    });

    function preventDefaults(e) {
        e.preventDefault();
        e.stopPropagation();
    }

    ['dragenter', 'dragover'].forEach(eventName => {
        receiptDropzone.addEventListener(eventName, () => {
            receiptDropzone.classList.add('border-indigo-500', 'bg-indigo-50');
        }, false);
    });

    ['dragleave', 'drop'].forEach(eventName => {
        receiptDropzone.addEventListener(eventName, () => {
            receiptDropzone.classList.remove('border-indigo-500', 'bg-indigo-50');
        }, false);
    });

    receiptDropzone.addEventListener('drop', handleReceiptDrop, false);

    function handleReceiptDrop(e) {
        const dt = e.dataTransfer;
        const files = dt.files;
        if (files.length > 0) {
            receiptInput.files = files;
            showReceiptPreview(files[0]);
        }
    }

    receiptInput.addEventListener('change', function() {
        if (this.files.length > 0) {
            showReceiptPreview(this.files[0]);
        }
    });

    function showReceiptPreview(file) {
        receiptFilename.textContent = file.name;
        receiptPreview.classList.remove('hidden');
    }

    function clearReceipt() {
        receiptInput.value = '';
        receiptPreview.classList.add('hidden');
    }
</script>
@endpush
