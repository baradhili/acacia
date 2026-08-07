<div class="bg-white rounded-lg shadow">
    <div class="widget-handle px-6 py-4 border-b border-gray-200 flex justify-between items-center bg-gray-50 cursor-grab">
        <h2 class="text-lg font-semibold text-gray-800">Outstanding PO Budgets</h2>
        <a href="{{ route('purchase-orders.index') }}" class="text-sm text-blue-600 hover:text-blue-800">View All</a>
    </div>
    <div class="p-6">
        @if($purchase_orders->isEmpty())
            <p class="text-gray-500 text-center py-4">No outstanding PO budgets</p>
        @else
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-gray-500">
                        <th class="pb-2">PO #</th>
                        <th class="pb-2">Project</th>
                        <th class="pb-2 text-right">Remaining</th>
                        <th class="pb-2 text-right">% Used</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($purchase_orders->take(5) as $po)
                    <tr class="border-t border-gray-100">
                        <td class="py-2">
                            <a href="{{ route('purchase-orders.show', $po['id']) }}" class="text-blue-600 hover:text-blue-800">
                                {{ $po['po_number'] }}
                            </a>
                        </td>
                        <td class="py-2">{{ $po['project_name'] }}</td>
                        <td class="py-2 text-right">${{ $po['remaining_formatted'] }}</td>
                        <td class="py-2 text-right">
                            <span class="{{ $po['is_over_budget'] ? 'text-red-600 font-medium' : '' }}">
                                {{ $po['utilization'] }}%
                            </span>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
</div>
