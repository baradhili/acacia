@extends('layouts.app')
@section('title', 'Edit Invoice ' . $invoice->invoice_number)
@section('content')

    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-800">Edit Invoice {{ $invoice->invoice_number }}</h1>
    </div>

    <form action="{{ route('invoices.update', $invoice) }}" method="POST" class="space-y-6">
        @csrf
        @method('PUT')

        <div class="bg-white rounded-lg shadow p-6">
            <h2 class="text-lg font-semibold text-gray-800 mb-4">Client & Project</h2>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Client *</label>
                    <select name="client_id" required
                        class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 w-full">
                        @foreach ($clients as $id => $name)
                            <option value="{{ $id }}"
                                {{ old('client_id', $invoice->client_id) == $id ? 'selected' : '' }}>{{ $name }}
                            </option>
                        @endforeach
                    </select>
                    @error('client_id')
                        <p class="text-red-500 text-sm mt-1">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Project</label>
                    <select name="project_id"
                        class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 w-full">
                        <option value="">Select Project</option>
                        @foreach ($projects as $clientId => $clientProjects)
                            @foreach ($clientProjects as $project)
                                <option value="{{ $project->id }}"
                                    {{ old('project_id', $invoice->project_id) == $project->id ? 'selected' : '' }}>
                                    {{ $project->name }}
                                </option>
                            @endforeach
                        @endforeach
                    </select>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-lg shadow p-6">
            <h2 class="text-lg font-semibold text-gray-800 mb-4">Invoice Details</h2>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Issue Date *</label>
                    <input type="date" name="issue_date"
                        value="{{ old('issue_date', $invoice->issue_date->toDateString()) }}" required
                        class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 w-full">
                    @error('issue_date')
                        <p class="text-red-500 text-sm mt-1">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Due Date *</label>
                    <input type="date" name="due_date"
                        value="{{ old('due_date', $invoice->due_date?->toDateString()) }}" required
                        class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 w-full">
                    @error('due_date')
                        <p class="text-red-500 text-sm mt-1">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            <div class="mt-4">
                <label class="block text-sm font-medium text-gray-700 mb-1">Notes</label>
                <textarea name="notes" rows="2"
                    class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 w-full">{{ old('notes', $invoice->notes) }}</textarea>
            </div>

            <div class="mt-4">
                <label class="block text-sm font-medium text-gray-700 mb-1">Terms & Conditions</label>
                <textarea name="terms" rows="2"
                    class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 w-full">{{ old('terms', $invoice->terms) }}</textarea>
            </div>
        </div>

        <div class="bg-white rounded-lg shadow p-6">
            <h2 class="text-lg font-semibold text-gray-800 mb-4">Line Items</h2>

            <div id="itemsContainer">
                @php $items = old('items', $invoice->items->toArray()); @endphp
                @foreach ($items as $index => $item)
                    <div class="item-row grid grid-cols-12 gap-2 mb-4 p-4 bg-gray-50 rounded-lg">
                        @if (!empty($item['id']))
                            <input type="hidden" name="items[{{ $index }}][id]" value="{{ $item['id'] }}">
                        @endif
                        <div class="col-span-4">
                            <label class="block text-xs font-medium text-gray-700 mb-1">Description</label>
                            <input type="text" name="items[{{ $index }}][description]"
                                value="{{ $item['description'] ?? '' }}" required
                                class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 w-full text-sm">
                        </div>
                        <div class="col-span-2">
                            <label class="block text-xs font-medium text-gray-700 mb-1">Qty</label>
                            <input type="number" name="items[{{ $index }}][quantity]"
                                value="{{ $item['quantity'] ?? 1 }}" step="0.01" min="0" required
                                class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 w-full text-sm quantity-input">
                        </div>
                        <div class="col-span-2">
                            <label class="block text-xs font-medium text-gray-700 mb-1">Unit Price</label>
                            <input type="number" name="items[{{ $index }}][unit_price]"
                                value="{{ $item['unit_price'] ?? 0 }}" step="0.01" min="0" required
                                class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 w-full text-sm unit-price-input">
                        </div>
                        <div class="col-span-1">
                            <label class="block text-xs font-medium text-gray-700 mb-1">Tax %</label>
                            <input type="number" name="items[{{ $index }}][tax_rate]"
                                value="{{ $item['tax_rate'] ?? 10 }}" step="0.01" min="0" max="100"
                                class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 w-full text-sm">
                        </div>
                        <div class="col-span-1">
                            <label class="block text-xs font-medium text-gray-700 mb-1">Disc %</label>
                            <input type="number" name="items[{{ $index }}][discount_percent]"
                                value="{{ $item['discount_percent'] ?? 0 }}" step="0.01" min="0" max="100"
                                class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 w-full text-sm">
                        </div>
                        <div class="col-span-2">
                            <label class="block text-xs font-medium text-gray-700 mb-1">Total</label>
                            <div class="text-sm font-medium text-gray-900 pt-5 line-total">$0.00</div>
                            @if ($index > 0)
                                <button type="button"
                                    class="remove-item text-red-600 hover:text-red-800 text-xs mt-1">Remove</button>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>

            <button type="button" id="addItemBtn"
                class="mt-4 bg-gray-100 hover:bg-gray-200 text-gray-700 px-4 py-2 rounded-lg text-sm">
                + Add Line Item
            </button>
        </div>

        <div class="flex justify-end gap-4">
            <a href="{{ route('invoices.show', $invoice) }}"
                class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-6 py-2 rounded-lg">
                Cancel
            </a>
            <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white px-6 py-2 rounded-lg">
                Update Invoice
            </button>
        </div>
    </form>

    <template id="itemTemplate">
        <div class="item-row grid grid-cols-12 gap-2 mb-4 p-4 bg-gray-50 rounded-lg">
            <div class="col-span-4">
                <label class="block text-xs font-medium text-gray-700 mb-1">Description</label>
                <input type="text" name="items[__INDEX__][description]" required
                    class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 w-full text-sm">
            </div>
            <div class="col-span-2">
                <label class="block text-xs font-medium text-gray-700 mb-1">Qty</label>
                <input type="number" name="items[__INDEX__][quantity]" value="1" step="0.01" min="0"
                    required
                    class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 w-full text-sm quantity-input">
            </div>
            <div class="col-span-2">
                <label class="block text-xs font-medium text-gray-700 mb-1">Unit Price</label>
                <input type="number" name="items[__INDEX__][unit_price]" value="0" step="0.01" min="0"
                    required
                    class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 w-full text-sm unit-price-input">
            </div>
            <div class="col-span-1">
                <label class="block text-xs font-medium text-gray-700 mb-1">Tax %</label>
                <input type="number" name="items[__INDEX__][tax_rate]" value="10" step="0.01" min="0"
                    max="100"
                    class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 w-full text-sm">
            </div>
            <div class="col-span-1">
                <label class="block text-xs font-medium text-gray-700 mb-1">Disc %</label>
                <input type="number" name="items[__INDEX__][discount_percent]" value="0" step="0.01"
                    min="0" max="100"
                    class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 w-full text-sm">
            </div>
            <div class="col-span-2">
                <label class="block text-xs font-medium text-gray-700 mb-1">Total</label>
                <div class="text-sm font-medium text-gray-900 pt-5 line-total">$0.00</div>
                <button type="button" class="remove-item text-red-600 hover:text-red-800 text-xs mt-1">Remove</button>
            </div>
        </div>
    </template>
    @push('scripts')
        <script>
            let itemIndex = {{ count(old('items', $invoice->items->toArray())) }};

            document.getElementById('addItemBtn').addEventListener('click', function() {
                const container = document.getElementById('itemsContainer');
                const template = document.getElementById('itemTemplate').innerHTML;
                const html = template.replace(/__INDEX__/g, itemIndex);
                container.insertAdjacentHTML('beforeend', html);
                itemIndex++;
                attachEventListeners();
            });

            function attachEventListeners() {
                document.querySelectorAll('.item-row').forEach(row => {
                    const qtyInput = row.querySelector('.quantity-input');
                    const priceInput = row.querySelector('.unit-price-input');
                    const taxInput = row.querySelector('input[name*="[tax_rate]"]');
                    const discInput = row.querySelector('input[name*="[discount_percent]"]');
                    const totalDiv = row.querySelector('.line-total');

                    function updateTotal() {
                        const qty = parseFloat(qtyInput.value) || 0;
                        const price = parseFloat(priceInput.value) || 0;
                        const tax = parseFloat(taxInput.value) || 0;
                        const disc = parseFloat(discInput.value) || 0;

                        const subtotal = qty * price;
                        const discountAmount = subtotal * (disc / 100);
                        const afterDiscount = subtotal - discountAmount;
                        const total = afterDiscount * (1 + tax / 100);

                        totalDiv.textContent = '$' + total.toFixed(2);
                    }

                    [qtyInput, priceInput, taxInput, discInput].forEach(input => {
                        if (input) input.addEventListener('input', updateTotal);
                    });

                    updateTotal();
                });

                document.querySelectorAll('.remove-item').forEach(btn => {
                    btn.addEventListener('click', function() {
                        this.closest('.item-row').remove();
                    });
                });
            }

            attachEventListeners();
        </script>
    @endpush

    <!-- Documents -->
    <div class="bg-white rounded-lg shadow p-6 mt-6">
        <h2 class="text-lg font-semibold text-gray-800 mb-4">Documents</h2>
        
        <!-- Upload Form -->
        <form id="documentUploadForm" class="mb-4">
            @csrf
            <input type="hidden" name="documentable_type" value="Invoice">
            <input type="hidden" name="documentable_id" value="{{ $invoice->id }}">
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
        @if($invoice->documents->count() > 0)
            <div class="border rounded-lg divide-y">
                @foreach($invoice->documents as $doc)
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
                formData.append('documentable_type', 'Invoice');
                formData.append('documentable_id', '{{ $invoice->id }}');

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
