@extends('layouts.app')
@section('title', 'Personal Services Income')
@section('content')

    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-800">Personal Services Income</h1>
        <p class="text-sm text-gray-500 mt-1">
            Service-work income (invoices raised from time entries — time for money) for FY{{ $income['fy'] }}
            ({{ $income['start']->format('d M Y') }} – {{ $income['end']->format('d M Y') }}).
            Breaching the 80% rule leaves the Results Test as the only PSB escape; failing it locks PSI mode.
        </p>
    </div>

    @if (session('success'))
        <div class="mb-4 bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg">{{ session('success') }}</div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        {{-- 80% rule --}}
        <div class="bg-white rounded-lg shadow p-6">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-lg font-semibold text-gray-800">The 80% rule</h2>
                @if($income['breaches_80'])
                    <span class="px-2 py-1 text-xs font-semibold rounded-full bg-red-100 text-red-800">BREACHED</span>
                @else
                    <span class="px-2 py-1 text-xs font-semibold rounded-full bg-green-100 text-green-800">Clear</span>
                @endif
            </div>
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs text-gray-500 uppercase">
                        <th class="py-2">Client</th>
                        <th class="py-2 text-right">Service income</th>
                        <th class="py-2 text-right">Share</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($income['rows'] as $row)
                        <tr class="{{ $row['share'] >= 0.8 ? 'bg-red-50' : '' }}">
                            <td class="py-2">{{ $row['client'] }}</td>
                            <td class="py-2 text-right">${{ number_format($row['amount'], 2) }}</td>
                            <td class="py-2 text-right {{ $row['share'] >= 0.8 ? 'font-bold text-red-700' : 'text-gray-600' }}">
                                {{ round($row['share'] * 100, 1) }}%
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="py-6 text-center text-gray-500">No time-entry-backed invoices this year.</td></tr>
                    @endforelse
                </tbody>
                <tfoot class="border-t border-gray-200">
                    <tr class="font-semibold">
                        <td class="py-2">Total service-work income</td>
                        <td class="py-2 text-right">${{ number_format($income['total'], 2) }}</td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
            <p class="text-xs text-gray-500 mt-3">
                Top client: {{ round($income['top_share'] * 100, 1) }}% of service income.
                At 80% or more from one client, the Unrelated Clients, Employment and Business Premises tests
                are unavailable — only the Results Test can keep you a PSB.
            </p>
        </div>

        {{-- Results test + mode --}}
        <div class="bg-white rounded-lg shadow p-6">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-lg font-semibold text-gray-800">PSB Results Test</h2>
                @if($setting->psi_mode)
                    <span class="px-2 py-1 text-xs font-semibold rounded-full bg-orange-100 text-orange-800">PSI MODE</span>
                @else
                    <span class="px-2 py-1 text-xs font-semibold rounded-full bg-green-100 text-green-800">PSB</span>
                @endif
            </div>

            <form method="POST" action="{{ route('psi.assess') }}" class="space-y-3">
                @csrf
                @php $answers = $setting->psb_results ?? []; @endphp
                <label class="flex items-start gap-2 text-sm text-gray-700">
                    <input type="hidden" name="answers[specific_result]" value="0">
                    <input type="checkbox" name="answers[specific_result]" value="1" class="mt-0.5"
                        {{ ($answers['specific_result'] ?? false) ? 'checked' : '' }}>
                    Paid to produce a <strong>specific result</strong> (a deliverable or milestone — not time)
                </label>
                <label class="flex items-start gap-2 text-sm text-gray-700">
                    <input type="hidden" name="answers[own_equipment]" value="0">
                    <input type="checkbox" name="answers[own_equipment]" value="1" class="mt-0.5"
                        {{ ($answers['own_equipment'] ?? false) ? 'checked' : '' }}>
                    You provide the <strong>equipment or tools</strong> for the work (beyond minor items)
                </label>
                <label class="flex items-start gap-2 text-sm text-gray-700">
                    <input type="hidden" name="answers[liable_for_defects]" value="0">
                    <input type="checkbox" name="answers[liable_for_defects]" value="1" class="mt-0.5"
                        {{ ($answers['liable_for_defects'] ?? false) ? 'checked' : '' }}>
                    You are <strong>liable for rectifying defects</strong> in the work
                </label>
                <button class="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm hover:bg-indigo-700">
                    Record answers
                </button>
                @if($setting->psi_assessed_at)
                    <p class="text-xs text-gray-400">Last assessed {{ $setting->psi_assessed_at->format('d M Y H:i') }}</p>
                @endif
            </form>

            @if($setting->psi_mode)
                <div class="mt-4 bg-orange-50 border border-orange-200 rounded-lg p-4 text-sm text-orange-800">
                    <p class="font-semibold mb-1">PSI mode — deductions restricted</p>
                    <p>No occupancy costs (rent, mortgage interest, rates, land tax), and no deductions for
                    payments to associates for non-principal work (admin, bookkeeping). Deduct only what an
                    employee could claim: work-related travel, vehicle costs without private use, home-office
                    running costs, professional development, tools and software.</p>
                </div>
            @endif
        </div>

        {{-- Attribution --}}
        <div class="bg-white rounded-lg shadow p-6 lg:col-span-2">
            <h2 class="text-lg font-semibold text-gray-800 mb-4">PSI attribution</h2>
            <table class="w-full max-w-lg text-sm">
                <tr>
                    <td class="py-2">PSI received (service-work income)</td>
                    <td class="py-2 text-right">${{ number_format($attribution['psi_income'], 2) }}</td>
                </tr>
                <tr class="border-t border-gray-100">
                    <td class="py-2">Less salary / wages promptly paid to the PSI workers</td>
                    <td class="py-2 text-right">-${{ number_format($attribution['wages_paid'], 2) }}</td>
                </tr>
                <tr class="border-t border-gray-200 font-bold">
                    <td class="py-2">Net PSI attributed to the individual</td>
                    <td class="py-2 text-right">${{ number_format($attribution['net_psi'], 2) }}</td>
                </tr>
            </table>
            <p class="text-xs text-gray-500 mt-3">
                The company is a conduit for net PSI — it goes on the individual's personal return (the company
                doesn't pay tax on it). Excess entity-maintenance deductions (tax and accounting fees) and other
                allowable deductions further reduce the attributed amount.
            </p>
        </div>
    </div>
@endsection
