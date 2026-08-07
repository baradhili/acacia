<div class="bg-white rounded-lg shadow">
    <div class="widget-handle px-6 py-4 border-b border-gray-200 flex justify-between items-center bg-gray-50 cursor-grab">
        <h2 class="text-lg font-semibold text-gray-800">Unbilled Time</h2>
        <a href="{{ route('time-entries.index') }}" class="text-sm text-blue-600 hover:text-blue-800">View All</a>
    </div>
    <div class="p-6">
        @if($entries->isEmpty())
            <p class="text-gray-500 text-center py-4">No unbilled time entries</p>
        @else
            <div class="mb-4">
                <p class="text-sm text-gray-500">Total</p>
                <p class="text-xl font-bold text-gray-800">{{ $total_hours }} hrs = ${{ $total_amount_formatted }}</p>
            </div>
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-gray-500">
                        <th class="pb-2">Date</th>
                        <th class="pb-2">Project</th>
                        <th class="pb-2 text-right">Hours</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($entries->take(5) as $entry)
                    <tr class="border-t border-gray-100">
                        <td class="py-2">{{ $entry['date'] }}</td>
                        <td class="py-2">{{ $entry['project_name'] }}</td>
                        <td class="py-2 text-right">{{ $entry['hours'] }} hrs</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
</div>
