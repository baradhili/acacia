@extends('layouts.app')

@section('title', 'New Journal Entry')

@section('header')
    <h2 class="text-xl font-semibold text-gray-800">New Journal Entry</h2>
@endsection

@section('content')
    <div class="bg-white rounded-lg shadow p-6 max-w-3xl">
        <form method="POST" action="{{ route('journal-entries.store') }}">
            @csrf
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700">Description</label>
                <input type="text" name="description" value="{{ old('description') }}"
                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                    placeholder="Journal entry description">
                @error('description')
                    <p class="text-red-500 text-sm mt-1">{{ $message }}</p>
                @enderror
            </div>

            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700">Date</label>
                <input type="date" name="date" value="{{ old('date', now()->format('Y-m-d')) }}"
                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
            </div>

            <h3 class="text-sm font-medium text-gray-700 mb-2">Debit Line</h3>
            <div class="grid grid-cols-2 gap-4 mb-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700">Account</label>
                    <select name="debit_account"
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                        @foreach($accounts as $id => $name)
                            <option value="{{ $id }}" {{ old('debit_account') == $id ? 'selected' : '' }}>{{ $name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">Amount</label>
                    <input type="number" step="0.01" name="debit_amount" value="{{ old('debit_amount') }}"
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                </div>
            </div>

            <h3 class="text-sm font-medium text-gray-700 mb-2">Credit Line</h3>
            <div class="grid grid-cols-2 gap-4 mb-6">
                <div>
                    <label class="block text-sm font-medium text-gray-700">Account</label>
                    <select name="credit_account"
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                        @foreach($accounts as $id => $name)
                            <option value="{{ $id }}" {{ old('credit_account') == $id ? 'selected' : '' }}>{{ $name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">Amount</label>
                    <input type="number" step="0.01" name="credit_amount" value="{{ old('credit_amount') }}"
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                </div>
            </div>

            @error('debit_amount')
                <p class="text-red-500 text-sm mb-4">{{ $message }}</p>
            @enderror

            <div class="flex justify-end gap-2">
                <a href="{{ route('journal-entries.index') }}" class="px-4 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300">Cancel</a>
                <button type="submit" name="Save" class="px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700">Save</button>
            </div>
        </form>
    </div>
@endsection
