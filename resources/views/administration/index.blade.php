@extends('layouts.app')
@section('title', 'Administration')
@section('content')

    <div class="mb-6 flex justify-between items-center">
        <h1 class="text-2xl font-bold text-gray-800">Administration</h1>
    </div>

    @if (session('success'))
        <div class="mb-4 bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg">
            {{ session('success') }}
        </div>
    @endif
    @if (session('error'))
        <div class="mb-4 bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg">
            {{ session('error') }}
        </div>
    @endif
    @if ($errors->any())
        <div class="mb-4 bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg">
            {{ $errors->first() }}
        </div>
    @endif

    <div class="bg-white rounded-lg shadow p-6 max-w-3xl">
        <h2 class="text-lg font-semibold text-gray-800 mb-1">Currently Open Year</h2>
        <p class="text-sm text-gray-500 mb-4">
            The financial year the books are being worked in. Pinning an earlier year is the starting point for
            backfilling history: it creates the year's reporting period so
            <a href="{{ route('opening-balances.index') }}" class="text-indigo-600 hover:text-indigo-800 underline">opening balances</a>
            can be entered for it and transactions dated in it can post. Closed years must be reopened first, and
            the year-end close still governs which years are locked.
        </p>

        <div class="mb-4 flex items-center gap-3">
            <span class="text-sm font-medium text-gray-700">Current:</span>
            <span class="px-2.5 py-0.5 rounded-full text-xs font-semibold
                {{ $storedOpenYear !== null ? 'bg-indigo-100 text-indigo-800' : 'bg-gray-100 text-gray-600' }}">
                FY {{ $currentYear }}
            </span>
            <span class="text-xs text-gray-500">
                {{ $storedOpenYear !== null ? 'set by administrator' : 'automatic — follows the calendar' }}
            </span>
        </div>

        @if ($storedOpenYear !== null && (int) $storedOpenYear !== (int) $currentYear)
            <div class="mb-4 bg-amber-50 border border-amber-200 text-amber-800 text-sm px-4 py-3 rounded-lg">
                The stored setting (FY {{ $storedOpenYear }}) has fallen outside the allowed window of
                FY {{ $window[0] }} – FY {{ $window[1] }} and is being ignored — the calendar year is in
                effect. Choose a fresh year below.
            </div>
        @endif

        <form method="POST" action="{{ route('administration.open-year.update') }}" class="flex items-end gap-3">
            @csrf
            @method('PUT')

            <div class="flex-1">
                <label for="open_year" class="block text-sm font-medium text-gray-700 mb-1">Open financial year</label>
                <select name="open_year" id="open_year"
                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                    <option value="auto" {{ $storedOpenYear === null ? 'selected' : '' }}>
                        Automatic — follow the calendar (FY {{ $clockYear }})
                    </option>
                    @foreach ($options as $option)
                        <option value="{{ $option->year }}"
                            {{ (int) $storedOpenYear === $option->year ? 'selected' : '' }}
                            {{ $option->closed ? 'disabled' : '' }}>
                            FY {{ $option->year }} ({{ $option->start->format('d M Y') }} – {{ $option->end->format('d M Y') }}){{ $option->closed ? ' — closed' : '' }}
                        </option>
                    @endforeach
                </select>
            </div>

            <button type="submit"
                class="px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-md hover:bg-indigo-700 shrink-0">
                Save
            </button>
        </form>
    </div>
@endsection
