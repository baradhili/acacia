@extends('layouts.app')
@section('title', 'Leads')
@section('content')

    <div class="mb-6 flex justify-between items-center">
        <div>
            <h1 class="text-2xl font-bold text-gray-800">Leads</h1>
            <p class="text-sm text-gray-500 mt-1">The sales funnel — new enquiries through to won and lost.</p>
        </div>
        <a href="{{ route('crm.leads.create') }}"
            class="px-4 py-2 bg-indigo-600 text-white rounded-md hover:bg-indigo-700 text-sm">New Lead</a>
    </div>

    @if (session('success'))
        <div class="mb-4 bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg">{{ session('success') }}</div>
    @endif
    @if (session('error'))
        <div class="mb-4 bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg">{{ session('error') }}</div>
    @endif

    <div class="grid grid-cols-1 md:grid-cols-5 gap-4 mb-6">
        @foreach ($funnel as $status => $stage)
            <div class="bg-white rounded-lg shadow p-4">
                <p class="text-xs font-semibold text-gray-500 uppercase tracking-wider">{{ \Modules\Crm\Models\Lead::labels()[$status] }}</p>
                <p class="text-2xl font-bold text-gray-800 mt-1">{{ $stage['count'] }}</p>
                <p class="text-xs text-gray-500 mt-1">${{ number_format($stage['value'], 2) }}</p>
            </div>
        @endforeach
    </div>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
        <div class="bg-indigo-50 rounded-lg p-4">
            <p class="text-xs font-semibold text-indigo-700 uppercase tracking-wider">Open pipeline</p>
            <p class="text-2xl font-bold text-indigo-900 mt-1">${{ number_format($pipelineValue, 2) }}</p>
        </div>
        <div class="bg-green-50 rounded-lg p-4">
            <p class="text-xs font-semibold text-green-700 uppercase tracking-wider">Weighted forecast</p>
            <p class="text-2xl font-bold text-green-900 mt-1">${{ number_format($forecast, 2) }}</p>
            <p class="text-xs text-green-600 mt-1">value × probability over open leads</p>
        </div>
        <div class="{{ $overdue > 0 ? 'bg-red-50' : 'bg-gray-50' }} rounded-lg p-4">
            <p class="text-xs font-semibold {{ $overdue > 0 ? 'text-red-700' : 'text-gray-600' }} uppercase tracking-wider">Follow-ups overdue</p>
            <p class="text-2xl font-bold {{ $overdue > 0 ? 'text-red-900' : 'text-gray-800' }} mt-1">{{ $overdue }}</p>
        </div>
    </div>

    <div class="bg-white rounded-lg shadow overflow-hidden mb-6">
        <form method="GET" class="px-4 py-3 border-b border-gray-200 flex flex-wrap items-end gap-3">
            <div>
                <label class="block text-xs font-medium text-gray-500 mb-1">Status</label>
                <select name="status" class="rounded-md border-gray-300 shadow-sm text-sm">
                    <option value="">All</option>
                    @foreach (\Modules\Crm\Models\Lead::STATUSES as $status)
                        <option value="{{ $status }}" {{ ($filters['status'] ?? '') === $status ? 'selected' : '' }}>
                            {{ \Modules\Crm\Models\Lead::labels()[$status] }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-500 mb-1">Source</label>
                <select name="source" class="rounded-md border-gray-300 shadow-sm text-sm">
                    <option value="">All</option>
                    @foreach (\Modules\Crm\Models\Lead::sources() as $source)
                        <option value="{{ $source }}" {{ ($filters['source'] ?? '') === $source ? 'selected' : '' }}>{{ str_replace('_', ' ', ucfirst($source)) }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-500 mb-1">Owner</label>
                <select name="owner_id" class="rounded-md border-gray-300 shadow-sm text-sm">
                    <option value="">All</option>
                    @foreach ($owners as $id => $name)
                        <option value="{{ $id }}" {{ (string) ($filters['owner_id'] ?? '') === (string) $id ? 'selected' : '' }}>{{ $name }}</option>
                    @endforeach
                </select>
            </div>
            <button type="submit" class="px-3 py-2 bg-slate-600 text-white text-sm rounded-md hover:bg-slate-700">Filter</button>
        </form>

        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Lead</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Owner</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Value</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Prob.</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Follow-up</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    @forelse ($leads as $lead)
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3">
                                <a href="{{ route('crm.leads.show', $lead) }}" class="text-sm font-medium text-gray-900 hover:text-indigo-700">{{ $lead->name }}</a>
                                @if ($lead->company)
                                    <span class="block text-xs text-gray-500">{{ $lead->company }}</span>
                                @endif
                            </td>
                            <td class="px-4 py-3">
                                <span class="px-2 py-0.5 text-xs font-semibold rounded-full
                                    {{ $lead->status === 'won' ? 'bg-green-100 text-green-800' : ($lead->status === 'lost' ? 'bg-gray-200 text-gray-600' : 'bg-blue-100 text-blue-800') }}">
                                    {{ $lead->label() }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-600">{{ $lead->owner?->name ?? '—' }}</td>
                            <td class="px-4 py-3 text-sm text-right text-gray-900">${{ number_format($lead->estimated_value, 2) }}</td>
                            <td class="px-4 py-3 text-sm text-right text-gray-600">{{ rtrim(rtrim(number_format($lead->probability, 1), '0'), '.') }}%</td>
                            <td class="px-4 py-3 text-sm {{ $lead->next_follow_up && $lead->next_follow_up->lt(today()) ? 'text-red-600 font-medium' : 'text-gray-600' }}">
                                {{ $lead->next_follow_up?->format('d M Y') ?? '—' }}
                            </td>
                            <td class="px-4 py-3 text-right">
                                <a href="{{ route('crm.leads.edit', $lead) }}" class="text-indigo-600 hover:text-indigo-800 text-sm">Edit</a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-4 py-6 text-sm text-gray-500">No leads yet — create the first one.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="px-4 py-3 border-t border-gray-200">{{ $leads->links() }}</div>
    </div>
@endsection
