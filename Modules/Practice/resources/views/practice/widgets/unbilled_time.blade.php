<div class="bg-white rounded-lg shadow">
    <div class="widget-handle px-6 py-4 border-b border-gray-200 flex justify-between items-center bg-gray-50 cursor-grab">
        <h2 class="text-lg font-semibold text-gray-800">{{ __('widgets.labels.unbilled_time') }}</h2>
        <a href="{{ route('time-entries.index') }}" class="text-sm text-blue-600 hover:text-blue-800">{{ __('widgets.view_all') }}</a>
    </div>
    <div class="p-6">
        @if($entries->isEmpty())
            <p class="text-gray-500 text-center py-4">{{ __('widgets.unbilled_time.empty') }}</p>
        @else
            <div class="mb-4">
                <p class="text-sm text-gray-500">{{ __('widgets.total') }}</p>
                <p class="text-xl font-bold text-gray-800">{{ __('widgets.unbilled_time.total_line', ['hours' => $total_hours, 'amount' => $total_amount_formatted]) }}</p>
            </div>
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-gray-500">
                        <th class="pb-2">{{ __('widgets.date') }}</th>
                        <th class="pb-2">{{ __('widgets.project') }}</th>
                        <th class="pb-2 text-right">{{ __('widgets.unbilled_time.hours_label') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($entries->take(5) as $entry)
                    <tr class="border-t border-gray-100">
                        <td class="py-2">{{ $entry['date'] }}</td>
                        <td class="py-2">{{ $entry['project_name'] }}</td>
                        <td class="py-2 text-right">{{ __('widgets.unbilled_time.hours', ['count' => $entry['hours']]) }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
</div>
