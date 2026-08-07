<div class="bg-white rounded-lg shadow">
    <div class="widget-handle px-6 py-4 border-b border-gray-200 flex justify-between items-center bg-gray-50 cursor-grab">
        <h2 class="text-lg font-semibold text-gray-800">Recent Payments</h2>
        <a href="{{ route('payments.index') }}" class="text-sm text-blue-600 hover:text-blue-800">View All</a>
    </div>
    <div class="p-6">
        @if($payments->isEmpty())
            <p class="text-gray-500 text-center py-4">No recent payments</p>
        @else
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-gray-500">
                        <th class="pb-2">Payment</th>
                        <th class="pb-2">Client</th>
                        <th class="pb-2 text-right">Amount</th>
                        <th class="pb-2 text-right">Date</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($payments->take(5) as $payment)
                    <tr class="border-t border-gray-100">
                        <td class="py-2">
                            <a href="{{ route('payments.show', $payment['id']) }}" class="text-blue-600 hover:text-blue-800">
                                {{ $payment['payment_number'] }}
                            </a>
                        </td>
                        <td class="py-2">{{ $payment['client_name'] }}</td>
                        <td class="py-2 text-right text-green-600 font-medium">${{ $payment['amount_formatted'] }}</td>
                        <td class="py-2 text-right">{{ $payment['payment_date'] }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
</div>
