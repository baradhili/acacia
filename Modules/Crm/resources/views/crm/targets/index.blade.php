@extends('layouts.app')
@section('title', 'Sales Targets')
@section('content')

    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-800">Sales Targets</h1>
        <p class="text-sm text-gray-500 mt-1">
            Monthly goals measured against the estimated value of leads won (converted) in the month.
        </p>
    </div>

    @if (session('success'))
        <div class="mb-4 bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg">{{ session('success') }}</div>
    @endif
    @if ($errors->any())
        <div class="mb-4 bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg">{{ $errors->first() }}</div>
    @endif

    <div class="bg-white rounded-lg shadow p-6 mb-6 max-w-xl">
        <h2 class="text-lg font-semibold text-gray-800 mb-4">Set a target</h2>
        <form method="POST" action="{{ route('crm.targets.store') }}" class="flex items-end gap-3">
            @csrf
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Month (first day)</label>
                <input type="date" name="month" value="{{ old('month', now()->startOfMonth()->toDateString()) }}" required
                    class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                @error('month') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Target amount</label>
                <input type="number" name="amount" value="{{ old('amount') }}" step="0.01" min="0" required
                    class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                @error('amount') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>
            <button type="submit" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm rounded-md shrink-0">Save</button>
        </form>
    </div>

    <div class="bg-white rounded-lg shadow overflow-hidden">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Month</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Target</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Won</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-1/3">Progress</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                @forelse ($targets as $target)
                    @php
                        // One aggregate query per row: achieved feeds both
                        // the amount shown and the progress bar.
                        $achieved = $target->achieved();
                        $pct = (float) $target->amount > 0
                            ? round(min($achieved / (float) $target->amount * 100, 999.9), 1)
                            : 0.0;
                    @endphp
                    <tr>
                        <td class="px-4 py-3 text-sm font-medium text-gray-900">{{ $target->month->format('M Y') }}</td>
                        <td class="px-4 py-3 text-sm text-right text-gray-900">${{ number_format($target->amount, 2) }}</td>
                        <td class="px-4 py-3 text-sm text-right text-gray-600">${{ number_format($achieved, 2) }}</td>
                        <td class="px-4 py-3">
                            <div class="w-full bg-gray-100 rounded-full h-3">
                                <div class="h-3 rounded-full {{ $pct >= 100 ? 'bg-green-500' : ($pct >= 50 ? 'bg-indigo-500' : 'bg-amber-500') }}"
                                    style="width: {{ min($pct, 100) }}%"></div>
                            </div>
                            <p class="text-xs text-gray-500 mt-1">{{ $pct }}%</p>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="px-4 py-6 text-sm text-gray-500">No targets set yet.</td></tr>
                @endforelse
            </tbody>
        </table>
        <div class="px-4 py-3 border-t border-gray-200">{{ $targets->links() }}</div>
    </div>
@endsection
