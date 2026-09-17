@extends('layouts.app')
@section('title', 'Lead — ' . $lead->name)
@section('content')

    <div class="mb-6 flex justify-between items-start">
        <div>
            <h1 class="text-2xl font-bold text-gray-800">{{ $lead->name }}</h1>
            <p class="text-sm text-gray-500 mt-1">
                @if ($lead->company){{ $lead->company }} · @endif
                @if ($lead->email){{ $lead->email }} · @endif
                @if ($lead->phone){{ $lead->phone }} @endif
            </p>
            <p class="mt-2">
                <span class="px-2 py-0.5 text-xs font-semibold rounded-full
                    {{ $lead->status === 'won' ? 'bg-green-100 text-green-800' : ($lead->status === 'lost' ? 'bg-gray-200 text-gray-600' : 'bg-blue-100 text-blue-800') }}">
                    {{ $lead->label() }}
                </span>
                @if ($lead->loss_reason)
                    <span class="ml-2 text-xs text-gray-500">lost — {{ $lead->loss_reason }}</span>
                @endif
            </p>
        </div>
        <div class="flex gap-2">
            <a href="{{ route('crm.leads.edit', $lead) }}" class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg text-sm">Edit</a>
            @if ($lead->canTransitionTo(\Modules\Crm\Models\Lead::STATUS_WON))
                <button type="button" onclick="document.getElementById('convert-form').classList.toggle('hidden')"
                    class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg text-sm">Convert to Client</button>
            @endif
            @if ($lead->estimate)
                <a href="{{ route('estimates.show', $lead->estimate) }}"
                    class="bg-indigo-50 hover:bg-indigo-100 text-indigo-700 px-4 py-2 rounded-lg text-sm">
                    Estimate {{ $lead->estimate->estimate_number }}
                </a>
            @elseif ($lead->canTransitionTo(\Modules\Crm\Models\Lead::STATUS_WON))
                <a href="{{ route('crm.leads.estimate', $lead) }}"
                    class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg text-sm">Prepare Estimate</a>
            @endif
            <form action="{{ route('crm.leads.destroy', $lead) }}" method="POST"
                onsubmit="return confirm('Delete this lead and its activities?');">
                @csrf
                @method('DELETE')
                <button class="bg-red-50 hover:bg-red-100 text-red-700 px-4 py-2 rounded-lg text-sm">Delete</button>
            </form>
        </div>
    </div>

    @if (session('success'))
        <div class="mb-4 bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg">{{ session('success') }}</div>
    @endif
    @if (session('error'))
        <div class="mb-4 bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg">{{ session('error') }}</div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-2 space-y-6">
            @if ($lead->canTransitionTo(\Modules\Crm\Models\Lead::STATUS_WON))
                <div id="convert-form" class="hidden bg-white rounded-lg shadow p-6">
                    <h2 class="text-lg font-semibold text-gray-800 mb-2">Convert to Client</h2>
                    <p class="text-sm text-gray-500 mb-4">Wins the lead and creates the client — the plan, value and contact details carry into the client record.</p>
                    <form action="{{ route('crm.leads.convert', $lead) }}" method="POST" class="flex items-end gap-3">
                        @csrf
                        <div class="flex-1">
                            <label class="block text-sm font-medium text-gray-700 mb-1">Client name</label>
                            <input type="text" name="client_name" value="{{ old('client_name', $lead->company ?: $lead->name) }}" required maxlength="255"
                                class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                        </div>
                        <button type="submit" class="px-4 py-2 bg-green-600 hover:bg-green-700 text-white text-sm rounded-md">Convert</button>
                    </form>
                </div>
            @endif

            <div class="bg-white rounded-lg shadow p-6">
                <h2 class="text-lg font-semibold text-gray-800 mb-4">The plan</h2>
                <p class="text-sm text-gray-600 whitespace-pre-line">{{ $lead->notes ?? '—' }}</p>
            </div>

            <div class="bg-white rounded-lg shadow p-6">
                <h2 class="text-lg font-semibold text-gray-800 mb-4">Activity</h2>
                <form action="{{ route('crm.leads.activities.store', $lead) }}" method="POST" class="mb-4">
                    @csrf
                    <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
                        <select name="type" class="rounded-md border-gray-300 shadow-sm text-sm">
                            @foreach (\Modules\Crm\Models\LeadActivity::TYPES as $type)
                                <option value="{{ $type }}" {{ old('type', 'note') === $type ? 'selected' : '' }}>{{ ucfirst($type) }}</option>
                            @endforeach
                        </select>
                        <input type="text" name="summary" value="{{ old('summary') }}" required maxlength="500" placeholder="What happened / what's next"
                            class="md:col-span-3 rounded-md border-gray-300 shadow-sm text-sm">
                    </div>
                    <div class="flex justify-end mt-3">
                        <button type="submit" class="px-4 py-2 bg-slate-600 hover:bg-slate-700 text-white text-sm rounded-md">Log</button>
                    </div>
                </form>

                @if ($lead->activities->isEmpty())
                    <p class="text-sm text-gray-500">No activity logged yet.</p>
                @else
                    <ul class="divide-y divide-gray-100">
                        @foreach ($lead->activities as $activity)
                            <li class="py-3">
                                <p class="text-sm text-gray-900">
                                    <span class="px-2 py-0.5 text-xs font-medium rounded-full bg-gray-100 text-gray-700">{{ ucfirst($activity->type) }}</span>
                                    {{ $activity->summary }}
                                </p>
                                @if ($activity->details)
                                    <p class="text-sm text-gray-600 mt-1 whitespace-pre-line">{{ $activity->details }}</p>
                                @endif
                                <p class="text-xs text-gray-400 mt-1">{{ $activity->happened_at?->format('d M Y H:i') ?? $activity->created_at->format('d M Y H:i') }} · {{ $activity->user?->name ?? 'system' }}</p>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>

        <div class="space-y-6">
            <div class="bg-white rounded-lg shadow p-6">
                <h2 class="text-lg font-semibold text-gray-800 mb-4">Funnel</h2>
                <form action="{{ route('crm.leads.status', $lead) }}" method="POST">
                    @csrf
                    <select name="status" class="block w-full rounded-md border-gray-300 shadow-sm text-sm mb-3">
                        @foreach (\Modules\Crm\Models\Lead::labels() as $value => $label)
                            <option value="{{ $value }}" {{ $lead->status === $value ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                    <input type="text" name="loss_reason" placeholder="Loss reason (when moving to Lost)" maxlength="255"
                        class="block w-full rounded-md border-gray-300 shadow-sm text-sm mb-3"
                        value="{{ old('loss_reason', $lead->loss_reason) }}">
                    <button type="submit" class="w-full px-4 py-2 bg-slate-600 hover:bg-slate-700 text-white text-sm rounded-md">Move</button>
                </form>
            </div>

            <div class="bg-white rounded-lg shadow p-6">
                <h2 class="text-lg font-semibold text-gray-800 mb-4">Details</h2>
                <dl class="space-y-3 text-sm">
                    <div><dt class="text-gray-500">Estimated value</dt><dd class="font-medium">${{ number_format($lead->estimated_value, 2) }}</dd></div>
                    <div><dt class="text-gray-500">Probability</dt><dd class="font-medium">{{ rtrim(rtrim(number_format($lead->probability, 1), '0'), '.') }}% → ${{ number_format($lead->weightedValue(), 2) }}</dd></div>
                    <div><dt class="text-gray-500">Source</dt><dd class="font-medium">{{ $lead->source ? str_replace('_', ' ', ucfirst($lead->source)) : '—' }}</dd></div>
                    <div><dt class="text-gray-500">Owner</dt><dd class="font-medium">{{ $lead->owner?->name ?? '—' }}</dd></div>
                    <div><dt class="text-gray-500">Next follow-up</dt><dd class="font-medium">{{ $lead->next_follow_up?->format('d M Y') ?? '—' }}</dd></div>
                    @if ($lead->client)
                        <div><dt class="text-gray-500">Converted to client</dt>
                            <dd><a href="{{ route('clients.show', $lead->client) }}" class="text-indigo-600 hover:text-indigo-800">{{ $lead->client->name }}</a></dd></div>
                    @endif
                    @if ($lead->estimate)
                        <div><dt class="text-gray-500">Estimate</dt>
                            <dd><a href="{{ route('estimates.show', $lead->estimate) }}" class="text-indigo-600 hover:text-indigo-800">{{ $lead->estimate->estimate_number }} — ${{ number_format($lead->estimate->total, 2) }}</a></dd></div>
                    @endif
                </dl>
            </div>
        </div>
    </div>
@endsection
