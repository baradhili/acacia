@extends('layouts.app')
@section('title', 'Edit Payment ' . $payment->payment_number)
@section('content')

    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-800">Edit Payment {{ $payment->payment_number }}</h1>
    </div>

    <form action="{{ route('payments.update', $payment) }}" method="POST" class="space-y-6">
        @csrf
        @method('PUT')

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
                                {{ old('client_id', $payment->client_id) == $id ? 'selected' : '' }}>{{ $name }}
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
                        <input type="number" name="amount" value="{{ old('amount', $payment->amount) }}" step="0.01" min="0.01"
                            required
                            class="pl-7 rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 w-full">
                    </div>
                    @error('amount')
                        <p class="text-red-500 text-sm mt-1">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Payment Date *</label>
                    <input type="date" name="payment_date" value="{{ old('payment_date', $payment->payment_date->toDateString()) }}"
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
                            <option value="{{ $value }}" {{ old('payment_method', $payment->payment_method) == $value ? 'selected' : '' }}>
                                {{ $label }}</option>
                        @endforeach
                    </select>
                    @error('payment_method')
                        <p class="text-red-500 text-sm mt-1">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Reference</label>
                    <input type="text" name="reference" value="{{ old('reference', $payment->reference) }}"
                        class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 w-full"
                        placeholder="Transaction ID, Cheque #, etc.">
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Notes</label>
                    <input type="text" name="notes" value="{{ old('notes', $payment->notes) }}"
                        class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 w-full">
                </div>
            </div>
        </div>

        @if ($payment->allocations->isNotEmpty())
            <div class="bg-yellow-50 rounded-lg shadow p-6 border border-yellow-200">
                <h2 class="text-lg font-semibold text-yellow-800 mb-4">⚠️ Existing Allocations</h2>
                <p class="text-sm text-yellow-700 mb-4">
                    This payment has existing invoice allocations. Editing the amount may cause allocation discrepancies.
                </p>
                <table class="min-w-full">
                    <thead>
                        <tr class="border-b border-yellow-200">
                            <th class="text-left py-2 text-xs font-medium text-yellow-700 uppercase">Invoice</th>
                            <th class="text-right py-2 text-xs font-medium text-yellow-700 uppercase">Amount</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        @foreach ($payment->allocations as $allocation)
                            <tr>
                                <td class="py-2">{{ $allocation->invoice->invoice_number }}</td>
                                <td class="py-2 text-right">${{ number_format($allocation->amount, 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        <div class="flex justify-end gap-4">
            <a href="{{ route('payments.show', $payment) }}"
                class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-6 py-2 rounded-lg">
                Cancel
            </a>
            <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white px-6 py-2 rounded-lg">
                Update Payment
            </button>
        </div>
    </form>

    <!-- Documents -->
    <div class="bg-white rounded-lg shadow p-6 mt-6">
        <h2 class="text-lg font-semibold text-gray-800 mb-4">Documents</h2>
        
        <!-- Upload Form -->
        <form id="documentUploadForm" class="mb-4">
            @csrf
            <input type="hidden" name="documentable_type" value="Payment">
            <input type="hidden" name="documentable_id" value="{{ $payment->id }}">
            <div id="documentUploadArea" class="border-2 border-dashed border-gray-300 rounded-lg p-4 text-center cursor-pointer hover:border-indigo-500 transition">
                <svg class="mx-auto h-8 w-8 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/>
                </svg>
                <p class="mt-1 text-sm text-gray-600">Drop files or click to upload</p>
                <p class="text-xs text-gray-500">PDF, JPG, PNG, DOC up to 20MB</p>
            </div>
            <input type="file" name="file" id="documentFile" class="hidden" accept=".pdf,.jpg,.jpeg,.png,.gif,.doc,.docx,.xls,.xlsx,.txt,.zip,.rar">
        </form>

        <!-- Document List -->
        @if($payment->documents->count() > 0)
            <div class="border rounded-lg divide-y">
                @foreach($payment->documents as $doc)
                    <div class="flex items-center justify-between p-3" id="doc-{{ $doc->id }}">
                        <div class="flex items-center">
                            <svg class="h-5 w-5 text-gray-400 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"/>
                            </svg>
                            <span class="text-sm font-medium text-gray-900">{{ $doc->name }}</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <a href="{{ route('documents.download', $doc) }}" class="text-indigo-600 hover:text-indigo-900 text-sm">Download</a>
                            <button type="button" class="text-red-600 hover:text-red-900 text-sm delete-doc-btn" data-doc-id="{{ $doc->id }}">Delete</button>
                        </div>
                    </div>
                @endforeach
            </div>
        @else
            <p class="text-sm text-gray-500 text-center py-2">No documents attached</p>
        @endif
    </div>

    @push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const uploadArea = document.getElementById('documentUploadArea');
            const fileInput = document.getElementById('documentFile');
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;

            if (uploadArea && fileInput) {
                uploadArea.addEventListener('click', () => fileInput.click());
                uploadArea.addEventListener('dragover', (e) => {
                    e.preventDefault();
                    uploadArea.classList.add('border-indigo-500', 'bg-indigo-50');
                });
                uploadArea.addEventListener('dragleave', () => uploadArea.classList.remove('border-indigo-500', 'bg-indigo-50'));
                uploadArea.addEventListener('drop', (e) => {
                    e.preventDefault();
                    uploadArea.classList.remove('border-indigo-500', 'bg-indigo-50');
                    if (e.dataTransfer.files.length) {
                        uploadFile(e.dataTransfer.files[0]);
                    }
                });
                fileInput.addEventListener('change', () => {
                    if (fileInput.files.length) {
                        uploadFile(fileInput.files[0]);
                    }
                });
            }

            function uploadFile(file) {
                if (!file) return;
                
                const formData = new FormData();
                formData.append('file', file);
                formData.append('documentable_type', 'Payment');
                formData.append('documentable_id', '{{ $payment->id }}');

                fetch('{{ route('documents.store') }}', {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': csrfToken,
                        'Accept': 'application/json',
                    },
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.id) {
                        window.location.reload();
                    }
                })
                .catch(error => {
                    console.error('Upload failed:', error);
                    alert('Upload failed. Please try again.');
                });
            }

            // Handle delete buttons
            document.querySelectorAll('.delete-doc-btn').forEach(btn => {
                btn.addEventListener('click', function() {
                    if (!confirm('Delete this document?')) return;
                    
                    const docId = this.dataset.docId;
                    const docElement = document.getElementById('doc-' + docId);
                    
                    fetch('/documents/' + docId, {
                        method: 'DELETE',
                        headers: {
                            'X-CSRF-TOKEN': csrfToken,
                            'Accept': 'application/json',
                        }
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (docElement) {
                            docElement.remove();
                        }
                        // Show "no documents" message if empty
                        const docList = document.querySelector('.border.rounded-lg.divide-y');
                        if (!docList || docList.children.length === 0) {
                            const container = docList?.parentElement;
                            if (container) {
                                container.innerHTML = '<p class="text-sm text-gray-500 text-center py-2">No documents attached</p>';
                            }
                        }
                    })
                    .catch(error => {
                        console.error('Delete failed:', error);
                        alert('Delete failed. Please try again.');
                    });
                });
            });
        });
    </script>
    @endpush
@endsection
