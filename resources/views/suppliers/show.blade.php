@extends('layouts.app')
@section('title', '{{ $supplier->name }}')
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

@endsection