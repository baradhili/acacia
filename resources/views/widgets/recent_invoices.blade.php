<div class="bg-white rounded-lg shadow">
    <div class="widget-handle px-6 py-4 border-b border-gray-200 flex justify-between items-center bg-gray-50 cursor-grab">
        <h2 class="text-lg font-semibold text-gray-800">Recent Invoices</h2>
        <a href="{{ route('invoices.index') }}" class="text-sm text-blue-600 hover:text-blue-800">View All</a>
    </div>
    <div class="p-6">
        @if($invoices->isEmpty())
            <p class="text-gray-500 text-center py-4">No recent invoices</p>
        @else
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-gray-500">
                        <th class="pb-2">Invoice</th>
                        <th class="pb-2">Client</th>
                        <th class="pb-2 text-right">Amount</th>
                        <th class="pb-2 text-right">Due</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($invoices->take(5) as $invoice)
                    <tr class="border-t border-gray-100">
                        <td class="py-2">
                            <a href="{{ route('invoices.show', $invoice['id']) }}" class="text-blue-600 hover:text-blue-800">
                                {{ $invoice['invoice_number'] }}
                            </a>
                        </td>
                        <td class="py-2">{{ $invoice['client_name'] }}</td>
                        <td class="py-2 text-right">${{ $invoice['total_formatted'] }}</td>
                        <td class="py-2 text-right">
                            <span class="{{ $invoice['is_overdue'] ? 'text-red-600 font-medium' : '' }}">
                                {{ $invoice['due_date'] }}
                            </span>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
</div>
