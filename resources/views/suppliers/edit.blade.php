@extends('layouts.app')
@section('title', 'Edit Supplier')
@section('content')

    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-800">Edit Supplier</h1>
    </div>

    <div class="bg-white rounded-lg shadow p-6">
        <form action="{{ route('suppliers.update', $supplier) }}" method="POST">
            @csrf
            @method('PUT')
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Name *</label>
                    <input type="text" name="name" value="{{ old('name', $supplier->name) }}" required
                           class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                    <input type="email" name="email" value="{{ old('email', $supplier->email) }}"
                           class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Phone</label>
                    <input type="text" name="phone" value="{{ old('phone', $supplier->phone) }}"
                           class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">ABN</label>
                    <input type="text" name="abn" value="{{ old('abn', $supplier->abn) }}"
                           class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                </div>
                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Address</label>
                    <input type="text" name="address" value="{{ old('address', $supplier->address) }}"
                           class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">City</label>
                    <input type="text" name="city" value="{{ old('city', $supplier->city) }}"
                           class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">State</label>
                    <input type="text" name="state" value="{{ old('state', $supplier->state) }}"
                           class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Postcode</label>
                    <input type="text" name="postcode" value="{{ old('postcode', $supplier->postcode) }}"
                           class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                </div>
                <x-country-select name="country" :value="$supplier->country" />
                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Notes</label>
                    <textarea name="notes" rows="3"
                              class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">{{ old('notes', $supplier->notes) }}</textarea>
                </div>
            </div>
            <div class="mt-6 flex justify-end gap-3">
                <a href="{{ route('suppliers.index') }}" class="px-4 py-2 text-gray-700 hover:text-gray-900">Cancel</a>
                <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg">Update Supplier</button>
            </div>
        </form>
    </div>

    <!-- Logo -->
    <div class="bg-white rounded-lg shadow p-6 mt-6">
        <h2 class="text-lg font-semibold text-gray-800 mb-4">Logo</h2>
        
        <div id="logoContainer" class="flex items-start gap-6">
            @if($supplier->logo_url)
                <div class="relative">
                    <img src="{{ $supplier->logo_url }}" alt="{{ $supplier->name }} Logo" class="h-24 w-auto object-contain border rounded-lg">
                    <button type="button" id="deleteLogoBtn" class="absolute -top-2 -right-2 bg-red-500 text-white rounded-full p-1 hover:bg-red-600">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>
            @else
                <div id="logoPlaceholder" class="h-24 w-48 border-2 border-dashed border-gray-300 rounded-lg flex items-center justify-center">
                    <p class="text-sm text-gray-500">No logo uploaded</p>
                </div>
            @endif
            
            <div class="flex-1">
                <form id="logoUploadForm">
                    @csrf
                    <div id="logoUploadArea" class="border-2 border-dashed border-gray-300 rounded-lg p-4 text-center cursor-pointer hover:border-indigo-500 transition">
                        <svg class="mx-auto h-8 w-8 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                        </svg>
                        <p class="mt-1 text-sm text-gray-600">Click or drop to upload logo</p>
                        <p class="text-xs text-gray-500">SVG, PNG, JPG (max 2MB)</p>
                    </div>
                    <input type="file" name="logo" id="logoFile" class="hidden" accept=".svg,.png,.jpg,.jpeg">
                </form>
            </div>
        </div>
    </div>

    <!-- Documents -->
    <div class="bg-white rounded-lg shadow p-6 mt-6">
        <h2 class="text-lg font-semibold text-gray-800 mb-4">Documents</h2>
        
        <!-- Upload Form -->
        <form id="documentUploadForm" class="mb-4">
            @csrf
            <input type="hidden" name="documentable_type" value="Supplier">
            <input type="hidden" name="documentable_id" value="{{ $supplier->id }}">
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
        @if($supplier->documents->count() > 0)
            <div class="border rounded-lg divide-y">
                @foreach($supplier->documents as $doc)
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
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;

        // Logo Upload
        const logoUploadArea = document.getElementById('logoUploadArea');
        const logoFileInput = document.getElementById('logoFile');
        
        if (logoUploadArea && logoFileInput) {
            logoUploadArea.addEventListener('click', () => logoFileInput.click());
            logoUploadArea.addEventListener('dragover', (e) => {
                e.preventDefault();
                logoUploadArea.classList.add('border-indigo-500', 'bg-indigo-50');
            });
            logoUploadArea.addEventListener('dragleave', () => logoUploadArea.classList.remove('border-indigo-500', 'bg-indigo-50'));
            logoUploadArea.addEventListener('drop', (e) => {
                e.preventDefault();
                logoUploadArea.classList.remove('border-indigo-500', 'bg-indigo-50');
                if (e.dataTransfer.files.length) {
                    uploadLogo(e.dataTransfer.files[0]);
                }
            });
            logoFileInput.addEventListener('change', () => {
                if (logoFileInput.files.length) {
                    uploadLogo(logoFileInput.files[0]);
                }
            });
        }

        function uploadLogo(file) {
            if (!file) return;
            
            const formData = new FormData();
            formData.append('logo', file);

            fetch('{{ route('suppliers.logo.store', $supplier) }}', {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': csrfToken,
                    'Accept': 'application/json',
                },
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    window.location.reload();
                }
            })
            .catch(error => {
                console.error('Upload failed:', error);
                alert('Upload failed. Please try again.');
            });
        }

        // Logo Delete
        const deleteLogoBtn = document.getElementById('deleteLogoBtn');
        if (deleteLogoBtn) {
            deleteLogoBtn.addEventListener('click', function() {
                if (!confirm('Delete this logo?')) return;
                
                fetch('{{ route('suppliers.logo.destroy', $supplier) }}', {
                    method: 'DELETE',
                    headers: {
                        'X-CSRF-TOKEN': csrfToken,
                        'Accept': 'application/json',
                    }
                })
                .then(response => response.json())
                .then(data => {
                    window.location.reload();
                })
                .catch(error => {
                    console.error('Delete failed:', error);
                    alert('Delete failed. Please try again.');
                });
            });
        }

        // Document Upload
        const uploadArea = document.getElementById('documentUploadArea');
        const fileInput = document.getElementById('documentFile');

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
            formData.append('documentable_type', 'Supplier');
            formData.append('documentable_id', '{{ $supplier->id }}');

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