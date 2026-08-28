@extends('layouts.app')
@section('title', $declaration->declaration_number)
@section('content')

    @php
        $isDraft = $declaration->status === \App\Models\DividendDeclaration::STATUS_DRAFT;
        $isApproved = $declaration->status === \App\Models\DividendDeclaration::STATUS_APPROVED;
        $isCompleted = $declaration->status === \App\Models\DividendDeclaration::STATUS_COMPLETED;
        $canCancel = $isDraft || $isApproved;
    @endphp

    <div class="mb-6 flex flex-wrap justify-between items-start gap-3">
        <div>
            <div class="flex items-center gap-3">
                <h1 class="text-2xl font-bold text-gray-800">{{ $declaration->declaration_number }}</h1>
                @php
                    $badge = match($declaration->status) {
                        'draft' => 'bg-gray-100 text-gray-700',
                        'approved' => 'bg-blue-100 text-blue-700',
                        'completed' => 'bg-green-100 text-green-800',
                        default => 'bg-red-100 text-red-700',
                    };
                @endphp
                <span class="px-2.5 py-1 text-xs font-medium rounded-full {{ $badge }}">{{ $declaration->statusLabel() }}</span>
            </div>
            <p class="text-sm text-gray-500 mt-1">
                {{ \App\Models\DividendDeclaration::dividendTypes()[$declaration->dividend_type] }} ·
                {{ $declaration->shareClass?->code }} · FY{{ $declaration->financial_year }} ·
                Declared {{ $declaration->declaration_date->format('d M Y') }} ·
                Books close {{ $declaration->books_close_date->format('d M Y') }} ·
                Payment {{ $declaration->payment_date->format('d M Y') }}
            </p>
        </div>
        <div class="flex flex-wrap gap-2">
            @if($isDraft)
                <a href="{{ route('dividends.edit', $declaration) }}"
                    class="px-4 py-2 rounded-lg text-sm font-medium text-gray-600 hover:bg-gray-100 border border-gray-300">Edit</a>
                <form method="POST" action="{{ route('dividends.calculate', $declaration) }}">
                    @csrf
                    <button class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg text-sm font-medium">
                        {{ $declaration->distributions()->exists() ? 'Recalculate lines' : 'Calculate lines' }}
                    </button>
                </form>
            @endif

            @if($isDraft && $declaration->total_cash_dividend > 0)
                <form method="POST" action="{{ route('dividends.approve', $declaration) }}"
                    onsubmit="return confirm('Approve and post Dr Dividends Paid / Cr Dividends Payable? The calculation locks.');">
                    @csrf
                    <button class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg text-sm font-medium">Approve</button>
                </form>
            @endif

            @if($isApproved)
                <a href="{{ route('dividends.payment-schedule.csv', $declaration) }}"
                    class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg text-sm font-medium">
                    Payment schedule (CSV)
                </a>
                <form method="POST" action="{{ route('dividends.record-payment', $declaration) }}"
                    onsubmit="return confirm('Record the run as paid at the bank? This posts Dr Dividends Payable / Cr Bank, creates the franking debit and emails shareholder statements.');">
                    @csrf
                    <button class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg text-sm font-medium">
                        Record payment
                    </button>
                </form>
            @endif

            @if($isCompleted)
                <form method="POST" action="{{ route('dividends.send-statements', $declaration) }}">
                    @csrf
                    <input type="hidden" name="force" value="1">
                    <button class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg text-sm font-medium">
                        Send statements again
                    </button>
                </form>
            @endif

            @if($canCancel)
                <form method="POST" action="{{ route('dividends.cancel', $declaration) }}"
                    onsubmit="return confirm('Cancel this declaration?@if($isApproved) The posted ledger entry will be reversed.@endif');">
                    @csrf
                    <button class="text-red-600 hover:text-red-800 px-4 py-2 rounded-lg text-sm font-medium border border-red-300">
                        Cancel
                    </button>
                </form>
            @endif
        </div>
    </div>

    @if (session('success'))
        <div class="mb-4 bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg">{{ session('success') }}</div>
    @endif
    @if (session('error'))
        <div class="mb-4 bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg">{{ session('error') }}</div>
    @endif

    @if($frankingDeficit)
        <div class="mb-4 bg-amber-50 border border-amber-200 text-amber-800 px-4 py-3 rounded-lg">
            <strong>Franking deficit warning:</strong> the franking account is currently in deficit for
            FY{{ $declaration->financial_year }} — see the
            <a href="{{ route('franking-account.index') }}" class="underline">franking account</a>.
        </div>
    @endif

    <div class="grid grid-cols-2 md:grid-cols-5 gap-4 mb-6">
        <div class="bg-white rounded-lg shadow p-5">
            <p class="text-xs font-medium text-gray-500 uppercase">Shares eligible</p>
            <p class="text-xl font-bold text-gray-800 mt-1">{{ number_format($declaration->total_shares_eligible) }}</p>
        </div>
        <div class="bg-white rounded-lg shadow p-5">
            <p class="text-xs font-medium text-gray-500 uppercase">Per share</p>
            <p class="text-xl font-bold text-gray-800 mt-1">${{ number_format((float) $declaration->amount_per_share, 4) }}</p>
            <p class="text-xs text-gray-400 mt-1">{{ number_format((float) $declaration->franking_percentage, 0) }}% franked
                @if((float) $declaration->franking_credit_rate != 30)
                    @ {{ number_format((float) $declaration->franking_credit_rate, 0) }}% rate
                @endif
            </p>
        </div>
        <div class="bg-white rounded-lg shadow p-5">
            <p class="text-xs font-medium text-gray-500 uppercase">Cash dividend</p>
            <p class="text-xl font-bold text-gray-800 mt-1">${{ number_format((float) $declaration->total_cash_dividend, 2) }}</p>
        </div>
        <div class="bg-white rounded-lg shadow p-5">
            <p class="text-xs font-medium text-gray-500 uppercase">Franking credit</p>
            <p class="text-xl font-bold text-indigo-800 mt-1">${{ number_format((float) $declaration->total_franking_credit, 2) }}</p>
            @if($isDraft && (float) $declaration->total_franking_credit > $availableFranking)
                <p class="text-xs text-red-600 mt-1">Exceeds available ${{ number_format($availableFranking, 2) }}</p>
            @endif
        </div>
        <div class="bg-white rounded-lg shadow p-5">
            <p class="text-xs font-medium text-gray-500 uppercase">Grossed-up total</p>
            <p class="text-xl font-bold text-gray-800 mt-1">${{ number_format((float) $declaration->total_grossed_up, 2) }}</p>
        </div>
    </div>

    @if($declaration->notes)
        <div class="bg-white rounded-lg shadow p-4 mb-6 text-sm text-gray-600">{{ $declaration->notes }}</div>
    @endif

    <div class="bg-white rounded-lg shadow overflow-hidden mb-6">
        <div class="px-4 py-3 border-b border-gray-200 flex flex-wrap justify-between items-center gap-2">
            <h2 class="font-semibold text-gray-800">
                @if(!$isDraft) Payment schedule &amp; distributions @else Distribution lines @endif
            </h2>
            @if(!$isDraft)
                <p class="text-xs text-gray-400">
                    Pay manually from this schedule (CSV download) — no payments are initiated by the ERP.
                </p>
            @endif
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Shareholder</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Shares</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Cash</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Franking credit</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Grossed-up</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Net payment</th>
                        @if(!$isDraft)
                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Bank details</th>
                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Reference</th>
                        @endif
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                        <th class="px-4 py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse($declaration->distributions as $distribution)
                        <tr>
                            <td class="px-4 py-2 font-medium text-gray-900">{{ $distribution->shareholder_name }}</td>
                            <td class="px-4 py-2 text-right">{{ number_format($distribution->shares_eligible) }}</td>
                            <td class="px-4 py-2 text-right">${{ number_format((float) $distribution->cash_dividend, 2) }}</td>
                            <td class="px-4 py-2 text-right">${{ number_format((float) $distribution->franking_credit, 2) }}</td>
                            <td class="px-4 py-2 text-right">${{ number_format((float) $distribution->grossed_up_dividend, 2) }}</td>
                            <td class="px-4 py-2 text-right font-medium">${{ number_format((float) $distribution->net_payment, 2) }}</td>
                            @if(!$isDraft)
                                <td class="px-4 py-2">
                                    @if($distribution->shareholder?->bankDetailsComplete())
                                        {{ $distribution->shareholder->bank_bsb }} / {{ $distribution->shareholder->bank_account_number }}
                                    @else
                                        <span class="text-amber-600">Missing</span>
                                    @endif
                                </td>
                                <td class="px-4 py-2 font-mono text-xs">{{ $distribution->payment_reference }}</td>
                            @endif
                            <td class="px-4 py-2">
                                <span class="px-2 py-0.5 text-xs rounded-full
                                    {{ $distribution->status === 'paid' ? 'bg-green-100 text-green-800' : ($distribution->status === 'cancelled' ? 'bg-gray-100 text-gray-500' : 'bg-yellow-100 text-yellow-700') }}">
                                    {{ $distribution->status }}
                                    @if($distribution->statement_sent) · stmt sent @endif
                                </span>
                            </td>
                            <td class="px-4 py-2 text-right">
                                @if($distribution->status === \App\Models\DividendDistribution::STATUS_PAID)
                                    <a href="{{ route('dividends.statements.pdf', $distribution) }}"
                                        class="text-indigo-600 hover:underline text-xs font-medium">Statement PDF</a>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ !$isDraft ? 10 : 7 }}" class="px-4 py-8 text-center text-gray-500">
                                @if($isDraft)
                                    No distribution lines yet — use <strong>Calculate lines</strong> to build them
                                    from the shareholdings ledger at the books-close date.
                                @else
                                    No distribution lines.
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @if($declaration->approved_at)
        <div class="bg-white rounded-lg shadow p-4 text-xs text-gray-500 space-y-1">
            <p>Approved by {{ $declaration->approvedBy?->name ?? '—' }} on {{ $declaration->approved_at->format('d M Y H:i') }}.</p>
            @if($declaration->ifrs_declaration_transaction_id)
                <p>Declaration journal: IFRS transaction #{{ $declaration->ifrs_declaration_transaction_id }}
                    (Dr Dividends Paid / Cr Dividends Payable — franking credits never post to the GL).</p>
            @endif
            @if($declaration->ifrs_payment_transaction_id)
                <p>Payment journal: IFRS transaction #{{ $declaration->ifrs_payment_transaction_id }}
                    (Dr Dividends Payable / Cr Bank), paid {{ $declaration->paid_at?->format('d M Y H:i') }}.</p>
            @endif
        </div>
    @endif
@endsection
