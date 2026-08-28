@extends('layouts.app')
@section('title', 'Share Classes')
@section('content')

    <div class="mb-6 flex justify-between items-center">
        <div>
            <h1 class="text-2xl font-bold text-gray-800">Share Classes</h1>
            <p class="text-sm text-gray-500 mt-1">
                Classes of shares the company has issued. Dividends are declared per class; a class without
                dividend rights never participates, and one without franking entitlement attaches no credits.
            </p>
        </div>
        <a href="{{ route('share-classes.create') }}"
            class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg text-sm font-medium">
            + Share Class
        </a>
    </div>

    @if (session('success'))
        <div class="mb-4 bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg">{{ session('success') }}</div>
    @endif
    @if (session('error'))
        <div class="mb-4 bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg">{{ session('error') }}</div>
    @endif

    <div class="bg-white rounded-lg shadow overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Code</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Description</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Voting</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Dividends</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Franking</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Ranking</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    @forelse($shareClasses as $class)
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3 font-medium text-gray-900">{{ $class->code }}</td>
                            <td class="px-4 py-3 text-gray-600">{{ $class->description ?: '—' }}</td>
                            <td class="px-4 py-3 text-center">{{ $class->voting_rights ? '✓' : '—' }}</td>
                            <td class="px-4 py-3 text-center">{{ $class->dividend_rights ? '✓' : '—' }}</td>
                            <td class="px-4 py-3 text-center">{{ $class->franking_entitlement ? '✓' : '—' }}</td>
                            <td class="px-4 py-3 text-right">{{ $class->ranking }}</td>
                            <td class="px-4 py-3">
                                <span class="px-2 py-1 text-xs font-medium rounded-full
                                    {{ $class->status === \App\Models\ShareClass::STATUS_ACTIVE ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-600' }}">
                                    {{ $class->status === \App\Models\ShareClass::STATUS_ACTIVE ? 'Active' : 'Inactive' }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-right space-x-3">
                                <a href="{{ route('share-classes.edit', $class) }}" class="text-indigo-600 hover:text-indigo-900 text-sm font-medium">Edit</a>
                                <form method="POST" action="{{ route('share-classes.destroy', $class) }}" class="inline"
                                    onsubmit="return confirm('Delete this share class?');">
                                    @csrf @method('DELETE')
                                    <button class="text-red-600 hover:text-red-800 text-sm font-medium">Delete</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="px-4 py-8 text-center text-gray-500">No share classes defined.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
