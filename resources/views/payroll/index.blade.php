@extends('layouts.app')
@section('title', 'Payroll')
@section('content')

    <div class="mb-6 flex justify-between items-center">
        <div>
            <h1 class="text-2xl font-bold text-gray-800">Payroll</h1>
            <p class="text-sm text-gray-500 mt-1">
                Australian pay runs: PAYG withheld per the ATO formulas (NAT 1004), super on ordinary
                earnings, and the accrual/payment journals — withheld PAYG lands in the same liability the
                <a href="{{ route('bas-settlements.index') }}" class="text-indigo-600 hover:underline">BAS settlement screen</a> nets.
            </p>
        </div>
        <div class="flex gap-2">
            <a href="{{ route('payroll.employees.index') }}"
                class="px-4 py-2 bg-white border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 text-sm">Employees</a>
            <a href="{{ route('payroll.runs.create') }}"
                class="px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 text-sm">New pay run</a>
        </div>
    </div>

    @if (session('success'))
        <div class="mb-4 bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg">{{ session('success') }}</div>
    @endif
    @if (session('error'))
        <div class="mb-4 bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg">{{ session('error') }}</div>
    @endif

    <div class="bg-white rounded-lg shadow overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Period</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Frequency</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Pay date</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Payslips</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Gross</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">PAYG</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Super</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    @forelse ($runs as $run)
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3 text-sm font-medium text-gray-900">
                                {{ $run->period_start->format('d M Y') }} – {{ $run->period_end->format('d M Y') }}
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-600">{{ ucfirst($run->frequency) }}</td>
                            <td class="px-4 py-3 text-sm text-gray-600">{{ $run->payment_date->format('d M Y') }}</td>
                            <td class="px-4 py-3 text-sm text-right text-gray-600">{{ $run->payslips->count() }}</td>
                            <td class="px-4 py-3 text-sm text-right text-gray-900">${{ number_format($run->payslips->sum('gross'), 2) }}</td>
                            <td class="px-4 py-3 text-sm text-right text-gray-600">${{ number_format($run->payslips->sum('payg_withheld'), 2) }}</td>
                            <td class="px-4 py-3 text-sm text-right text-gray-600">${{ number_format($run->payslips->sum('super'), 2) }}</td>
                            <td class="px-4 py-3">
                                <span class="px-2 py-1 text-xs font-medium rounded-full
                                    {{ $run->isProcessed() ? 'bg-green-100 text-green-800' : 'bg-amber-100 text-amber-800' }}">
                                    {{ $run->isProcessed() ? 'Processed' : 'Draft' }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-right">
                                <a href="{{ route('payroll.runs.show', $run) }}"
                                    class="text-indigo-600 hover:text-indigo-900 text-sm font-medium">Open</a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="px-4 py-8 text-center text-gray-500">
                                No pay runs yet.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
