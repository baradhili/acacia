<div class="bg-white rounded-lg shadow">
    <div class="widget-handle px-6 py-4 border-b border-gray-200 flex justify-between items-center bg-gray-50 cursor-grab">
        <h2 class="text-lg font-semibold text-gray-800">{{ __('widgets.labels.recent_invoices') }}</h2>
        <a href="{{ route('invoices.index') }}" class="text-sm text-blue-600 hover:text-blue-800">{{ __('widgets.view_all') }}</a>
    </div>
    <div class="p-6">
        @if($invoices->isEmpty())
            <p class="text-gray-500 text-center py-4">{{ __('widgets.recent_invoices.empty') }}</p>
        @else
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-gray-500">
                        <th class="pb-2">{{ __('widgets.recent_invoices.invoice') }}</th>
                        <th class="pb-2">{{ __('widgets.client') }}</th>
                        <th class="pb-2 text-right">{{ __('widgets.amount') }}</th>
                        <th class="pb-2 text-right">{{ __('widgets.recent_invoices.due') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($invoices->take(5) as $invoice)
                    <tr class="border-t border-gray-100">
                        <td class="py-2">
                            @if ($invoice['status'] === \App\Models\Invoice::STATUS_PAID)
                                <a href="{{ route('invoices.show', $invoice['id']) }}" class="text-green-700 hover:text-green-800 font-medium">
                                    {{ $invoice['invoice_number'] }}
                                </a>
                                <span class="inline-flex align-middle text-green-600" title="{{ __('widgets.recent_invoices.paid') }}">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"></path>
                                    </svg>
                                    <span class="sr-only">{{ __('widgets.recent_invoices.paid') }}</span>
                                </span>
                            @else
                                <a href="{{ route('invoices.show', $invoice['id']) }}" class="text-blue-600 hover:text-blue-800">
                                    {{ $invoice['invoice_number'] }}
                                </a>
                            @endif
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
