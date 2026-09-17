<div class="h-full flex flex-col">
    <div class="flex items-baseline justify-between mb-3">
        <h3 class="text-sm font-semibold text-gray-700">Sales pipeline</h3>
        <a href="{{ route('crm.leads.index') }}" class="text-xs text-indigo-600 hover:text-indigo-800">Leads →</a>
    </div>
    <div class="grid grid-cols-3 gap-2 mb-3 text-center">
        <div>
            <p class="text-lg font-bold text-indigo-700">${{ number_format($pipelineValue, 2) }}</p>
            <p class="text-xs text-gray-500">open</p>
        </div>
        <div>
            <p class="text-lg font-bold text-green-700">${{ number_format($forecast, 2) }}</p>
            <p class="text-xs text-gray-500">forecast</p>
        </div>
        <div>
            <p class="text-lg font-bold {{ $overdue > 0 ? 'text-red-600' : 'text-gray-700' }}">{{ $overdue }}</p>
            <p class="text-xs text-gray-500">overdue</p>
        </div>
    </div>
    @if ($leads->isEmpty())
        <p class="text-xs text-gray-400 text-center py-2">No open leads.</p>
    @else
        <ul class="divide-y divide-gray-100 text-xs">
            @foreach ($leads as $lead)
                <li class="flex justify-between items-center py-1.5">
                    <a href="{{ route('crm.leads.show', $lead) }}" class="text-gray-800 hover:text-indigo-700 truncate">{{ $lead->name }}</a>
                    <span class="text-gray-500 shrink-0 ml-2">${{ number_format($lead->estimated_value, 0) }}</span>
                </li>
            @endforeach
        </ul>
    @endif
</div>
