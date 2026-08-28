@extends('layouts.app')
@section('title', 'Add Supplier')
@section('content')

    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-800">Add Supplier</h1>
    </div>

    <div class="bg-white rounded-lg shadow p-6">
        <form action="{{ route('suppliers.store') }}" method="POST">
            @csrf
            @include('suppliers.partials.fields')
            <div class="mt-6 flex justify-end gap-3">
                <a href="{{ route('suppliers.index') }}" class="px-4 py-2 text-gray-700 hover:text-gray-900">Cancel</a>
                <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg">Save Supplier</button>
            </div>
        </form>
    </div>

    <!-- Document Upload Info -->
    <div class="bg-blue-50 rounded-lg shadow p-6 mt-6 border border-blue-200">
        <div class="flex items-center">
            <svg class="h-5 w-5 text-blue-500 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            <p class="text-sm text-blue-800">
                <strong>Note:</strong> You can upload documents after creating the supplier in the edit view.
            </p>
        </div>
    </div>

@endsection