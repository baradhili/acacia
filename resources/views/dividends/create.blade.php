@extends('layouts.app')
@section('title', 'New Dividend Declaration')
@section('content')

    <div class="mb-6 flex justify-between items-center">
        <h1 class="text-2xl font-bold text-gray-800">New Dividend Declaration</h1>
        <a href="{{ route('dividends.index') }}" class="text-sm text-indigo-600 hover:underline">← Declarations</a>
    </div>

    @if (session('error'))
        <div class="mb-4 bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg">{{ session('error') }}</div>
    @endif

    <div class="bg-white rounded-lg shadow p-6 max-w-3xl">
        <p class="text-sm text-gray-500 mb-4">
            Franking credits available for a new dividend:
            <strong>${{ number_format($availableFranking, 2) }}</strong>
            (after approved-but-unpaid declarations and estimated entries). Approval is blocked if the calculated
            franking credits exceed this.
        </p>
        <p class="text-sm text-gray-500 mb-4">
            The franking credit rate defaults to the company's classification —
            <strong>{{ $taxRateTypeLabel }}</strong> at {{ rtrim(rtrim(number_format($frankingRate, 2), '0'), '.') }}%.
            The rate is snapshotted onto the declaration and can be overridden for this run only.
        </p>

        <form method="POST" action="{{ route('dividends.store') }}">
            @csrf
            @include('dividends.form', ['declaration' => new \App\Models\DividendDeclaration()])
            <div class="mt-6 flex gap-3">
                <button class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg text-sm font-medium">
                    Create draft declaration
                </button>
                <a href="{{ route('dividends.index') }}"
                    class="px-4 py-2 rounded-lg text-sm font-medium text-gray-600 hover:bg-gray-100">Cancel</a>
            </div>
        </form>
    </div>
@endsection
