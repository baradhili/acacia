@extends('layouts.app')
@section('title', 'Edit Purchase Order')
@section('content')

    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-800">Edit Purchase Order</h1>
    </div>

    <div class="bg-white rounded-lg shadow p-6">
        <form action="{{ route('purchase-orders.update', $purchaseOrder) }}" method="POST">
            @csrf
            @method('PUT')

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <label for="client_id" class="block text-sm font-medium text-gray-700">Client *</label>
                    <select name="client_id" id="client_id" required
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <option value="">Select Client</option>
                        @foreach($clients as $id => $name)
                            <option value="{{ $id }}" {{ $purchaseOrder->client_id == $id ? 'selected' : '' }}>{{ $name }}</option>
                        @endforeach
                    </select>
                    @error('client_id')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="title" class="block text-sm font-medium text-gray-700">Title *</label>
                    <input type="text" name="title" id="title" value="{{ old('title', $purchaseOrder->title) }}" required
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                    @error('title')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div class="md:col-span-2">
                    <label for="description" class="block text-sm font-medium text-gray-700">Description</label>
                    <textarea name="description" id="description" rows="2"
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">{{ old('description', $purchaseOrder->description) }}</textarea>
                </div>

                <div>
                    <label for="budgeted_amount" class="block text-sm font-medium text-gray-700">Budgeted Amount ($) *</label>
                    <input type="number" name="budgeted_amount" id="budgeted_amount" value="{{ old('budgeted_amount', $purchaseOrder->budgeted_amount) }}" step="0.01" min="0" required
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                    @error('budgeted_amount')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="start_date" class="block text-sm font-medium text-gray-700">Start Date</label>
                    <input type="date" name="start_date" id="start_date" value="{{ old('start_date', $purchaseOrder->start_date?->format('Y-m-d')) }}"
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                </div>

                <div>
                    <label for="end_date" class="block text-sm font-medium text-gray-700">End Date</label>
                    <input type="date" name="end_date" id="end_date" value="{{ old('end_date', $purchaseOrder->end_date?->format('Y-m-d')) }}"
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                </div>
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <a href="{{ route('purchase-orders.show', $purchaseOrder) }}" class="bg-gray-300 hover:bg-gray-400 text-gray-800 px-4 py-2 rounded-lg">
                    Cancel
                </a>
                <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg">
                    Update Purchase Order
                </button>
            </div>
        </form>
    </div>

    <!-- Documents -->
    <div class="bg-white rounded-lg shadow p-6 mt-6">
        <h2 class="text-lg font-semibold text-gray-800 mb-4">Documents</h2>
        
        <!-- Upload Form -->
        <form id="documentUploadForm" class="mb-4">
            @csrf
            <input type="hidden" name="documentable_type" value="PurchaseOrder">
            <input type="hidden" name="documentable_id" value="{{ $purchaseOrder->id }}">
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
        @if($purchaseOrder->documents->count() > 0)
            <div class="border rounded-lg divide-y">
                @foreach($purchaseOrder->documents as $doc)
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
            formData.append('documentable_type', 'PurchaseOrder');
            formData.append('documentable_id', '{{ $purchaseOrder->id }}');

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