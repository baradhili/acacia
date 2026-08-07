@extends('layouts.app')
@section('title', $supplier->name)
@section('content')

    <div class="mb-6 flex justify-between items-center">
        <h1 class="text-2xl font-bold text-gray-800">{{ $supplier->name }}</h1>
        <div class="flex gap-3">
            <a href="{{ route('suppliers.edit', $supplier) }}" class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg">
                Edit Supplier
            </a>
            <a href="{{ route('suppliers.index') }}" class="bg-gray-600 hover:bg-gray-700 text-white px-4 py-2 rounded-lg">
                Back
            </a>
        </div>
    </div>

    <div class="bg-white rounded-lg shadow p-6">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div>
                <h3 class="text-sm font-medium text-gray-500">Contact Information</h3>
                <dl class="mt-4 space-y-3">
                    <div class="flex justify-between">
                        <dt class="text-gray-600">Email:</dt>
                        <dd class="text-gray-900">{{ $supplier->email ?? '-' }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-gray-600">Phone:</dt>
                        <dd class="text-gray-900">{{ $supplier->phone ?? '-' }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-gray-600">ABN:</dt>
                        <dd class="text-gray-900">{{ $supplier->abn ?? '-' }}</dd>
                    </div>
                </dl>
            </div>
            <div>
                <h3 class="text-sm font-medium text-gray-500">Address</h3>
                <dl class="mt-4 space-y-3">
                    <div>
                        <dt class="text-gray-600">Street:</dt>
                        <dd class="text-gray-900">{{ $supplier->address ?? '-' }}</dd>
                    </div>
                    <div class="flex gap-4">
                        <div>
                            <dt class="text-gray-600">City:</dt>
                            <dd class="text-gray-900">{{ $supplier->city ?? '-' }}</dd>
                        </div>
                        <div>
                            <dt class="text-gray-600">State:</dt>
                            <dd class="text-gray-900">{{ $supplier->state ?? '-' }}</dd>
                        </div>
                        <div>
                            <dt class="text-gray-600">Postcode:</dt>
                            <dd class="text-gray-900">{{ $supplier->postcode ?? '-' }}</dd>
                        </div>
                    </div>
                    <div>
                        <dt class="text-gray-600">Country:</dt>
                        <dd class="text-gray-900">{{ $supplier->country ?? '-' }}</dd>
                    </div>
                </dl>
            </div>
        </div>
        @if($supplier->notes)
            <div class="mt-6 pt-6 border-t">
                <h3 class="text-sm font-medium text-gray-500">Notes</h3>
                <p class="mt-2 text-gray-700">{{ $supplier->notes }}</p>
            </div>
        @endif
    </div>

    <!-- Documents -->
    <div class="bg-white rounded-lg shadow p-6 mt-6">
        <div class="flex justify-between items-center mb-4">
            <h2 class="text-lg font-semibold text-gray-800">Documents</h2>
            <a href="{{ route('suppliers.edit', $supplier) }}" class="text-sm text-indigo-600 hover:text-indigo-800">
                Upload/Delete in Edit View →
            </a>
        </div>

        @if($supplier->documents->count() > 0)
            <div class="border rounded-lg divide-y">
                @foreach($supplier->documents as $doc)
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

@endsection