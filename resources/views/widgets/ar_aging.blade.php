<div class="bg-white rounded-lg shadow">
    <div class="widget-handle px-6 py-4 border-b border-gray-200 bg-gray-50 cursor-grab">
        <h2 class="text-lg font-semibold text-gray-800">AR Aging Summary</h2>
    </div>
    <div class="p-6">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-gray-500">
                    <th class="pb-2">Aging Bucket</th>
                    <th class="pb-2 text-right">Amount</th>
                    <th class="pb-2 text-right">%</th>
                </tr>
            </thead>
            <tbody>
                @foreach($aging_buckets as $bucket)
                <tr class="border-t border-gray-100">
                    <td class="py-2">{{ $bucket['label'] }}</td>
                    <td class="py-2 text-right">${{ number_format($bucket['amount'], 2) }}</td>
                    <td class="py-2 text-right">{{ $bucket['percent'] }}%</td>
                </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr class="border-t-2 border-gray-300 font-bold">
                    <td class="py-2">Total</td>
                    <td class="py-2 text-right">${{ $total_formatted }}</td>
                    <td class="py-2 text-right">100%</td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>
