@extends('layouts.app')
@section('title', 'AASB 1054 Franking Disclosure')
@section('content')

    <div class="mb-6 flex flex-wrap justify-between items-center gap-3">
        <div>
            <h1 class="text-2xl font-bold text-gray-800">AASB 1054 Franking Credit Disclosure</h1>
            <p class="text-sm text-gray-500 mt-1">
                Annual report disclosure of franking credits available for use in subsequent financial years (AASB 1054.13).
            </p>
        </div>
        <div class="flex items-center gap-3">
            <form method="GET" action="{{ route('franking-account.disclosure') }}" class="flex gap-2">
                <select name="year" onchange="this.form.submit()" class="border-gray-300 rounded-lg text-sm">
                    @foreach($years as $y)
                        <option value="{{ $y }}" {{ $y === $data['year'] ? 'selected' : '' }}>FY{{ $y }}</option>
                    @endforeach
                </select>
            </form>
            <a href="{{ route('franking-account.disclosure.pdf', ['year' => $data['year']]) }}"
                class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg text-sm font-medium">
                Download PDF
            </a>
        </div>
    </div>

    <div class="bg-white rounded-lg shadow p-8 max-w-3xl">
        <h2 class="font-semibold text-gray-800 mb-2">Note — Franking credits (FY{{ $data['year'] }})</h2>

        @if($data['deficit'])
            <div class="mb-4 bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg">
                <strong>Warning:</strong> the franking account closed FY{{ $data['year'] }} in deficit
                (${{ number_format($data['closing_balance'], 2) }}) — franking deficit tax applies.
            </div>
        @endif

        <table class="min-w-full text-sm mb-6">
            <tbody>
                <tr class="border-b border-gray-200">
                    <td class="py-2 text-gray-700">Franking account balance at 30 June {{ $data['year'] + 1 }}</td>
                    <td class="py-2 text-right font-medium">${{ number_format($data['closing_balance'], 2) }}</td>
                </tr>
                <tr class="border-b border-gray-200">
                    <td class="py-2 text-gray-700">(a) Franking credits that will arise on payment of the provision for income tax (estimated entries)</td>
                    <td class="py-2 text-right font-medium">${{ number_format($data['anticipated_credits'], 2) }}</td>
                </tr>
                <tr class="border-b border-gray-200">
                    <td class="py-2 text-gray-700">(b) Franking debits that will arise on payment of dividends recognised as a liability (approved, unpaid)</td>
                    <td class="py-2 text-right font-medium">(${{ number_format(abs($data['anticipated_debits']), 2) }})</td>
                </tr>
                <tr>
                    <td class="py-3 font-semibold text-gray-900">Franking credits available for use in subsequent financial years</td>
                    <td class="py-3 text-right text-lg font-bold {{ $data['available'] < 0 ? 'text-red-600' : 'text-gray-900' }}">
                        ${{ number_format($data['available'], 2) }}
                    </td>
                </tr>
            </tbody>
        </table>

        <div class="border-l-4 border-gray-200 pl-4 text-sm text-gray-600 leading-relaxed">
            <p>Franking credits available for use in subsequent financial years: ${{ number_format($data['available'], 2) }}.</p>
            <p class="mt-2">
                The above amount represents the balance of the franking account as at the reporting date adjusted for:
                (a) franking credits that will arise from the payment of the amount of the provision for income tax
                (${{ number_format($data['anticipated_credits'], 2) }}); and (b) franking debits that will arise from the
                payment of dividends recognised as a liability at the reporting date
                (${{ number_format(abs($data['anticipated_debits']), 2) }}).
            </p>
        </div>
    </div>
@endsection
