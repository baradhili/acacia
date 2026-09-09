@extends('layouts.app')
@section('title', 'New pay run')
@section('content')

    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-800">New pay run</h1>
        <p class="text-sm text-gray-500 mt-1">
            Salaried employees are prefilled from their annual salary; hourly staff wait for hours on the run.
        </p>
    </div>

    @if (session('error'))
        <div class="mb-4 bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg">{{ session('error') }}</div>
    @endif

    <form method="POST" action="{{ route('payroll.runs.store') }}"
        class="bg-white rounded-lg shadow p-6 max-w-2xl space-y-4">
        @csrf

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Frequency</label>
                <select name="frequency" class="w-full border-gray-300 rounded-lg text-sm">
                    @foreach (\App\Models\PayRun::FREQUENCIES as $frequency)
                        <option value="{{ $frequency }}" {{ old('frequency', 'fortnightly') === $frequency ? 'selected' : '' }}>
                            {{ ucfirst($frequency) }}
                        </option>
                    @endforeach
                </select>
                @error('frequency') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Payment date</label>
                <input type="date" name="payment_date" value="{{ old('payment_date') }}" required
                    class="w-full border-gray-300 rounded-lg text-sm">
                @error('payment_date') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Period start</label>
                <input type="date" name="period_start" value="{{ old('period_start') }}" required
                    class="w-full border-gray-300 rounded-lg text-sm">
                @error('period_start') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Period end</label>
                <input type="date" name="period_end" value="{{ old('period_end') }}" required
                    class="w-full border-gray-300 rounded-lg text-sm">
                @error('period_end') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Notes</label>
            <textarea name="notes" rows="2" class="w-full border-gray-300 rounded-lg text-sm">{{ old('notes') }}</textarea>
            @error('notes') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
        </div>

        <div class="flex justify-end gap-2 pt-2">
            <a href="{{ route('payroll.index') }}"
                class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 text-sm hover:bg-gray-50">Cancel</a>
            <button class="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm hover:bg-indigo-700">Create run</button>
        </div>
    </form>
@endsection
