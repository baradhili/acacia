@extends('layouts.app')
@section('title', 'Pay run')
@section('content')

    <div class="mb-6 flex justify-between items-start">
        <div>
            <h1 class="text-2xl font-bold text-gray-800">{{ $run->label() }}</h1>
            <p class="text-sm text-gray-500 mt-1">
                Paid {{ $run->payment_date->format('d M Y') }}
                @if($run->notes) — {{ $run->notes }} @endif
            </p>
        </div>
        <div class="flex gap-2">
            @unless($run->isProcessed())
                <form method="POST" action="{{ route('payroll.runs.process', $run) }}"
                    onsubmit="return confirm('Process this run? The accrual and payment journals will post.');">
                    @csrf
                    <button class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 text-sm font-medium">
                        Process run
                    </button>
                </form>
                <form method="POST" action="{{ route('payroll.runs.destroy', $run) }}"
                    onsubmit="return confirm('Delete this draft run and its payslips?');">
                    @csrf @method('DELETE')
                    <button class="px-4 py-2 border border-red-300 text-red-600 rounded-lg hover:bg-red-50 text-sm">
                        Delete
                    </button>
                </form>
            @else
                <form method="POST" action="{{ route('payroll.runs.reverse', $run) }}"
                    onsubmit="return confirm('Reverse this run? The journals mirror back out and the run returns to draft.');">
                    @csrf
                    <button class="px-4 py-2 border border-amber-300 text-amber-700 rounded-lg hover:bg-amber-50 text-sm font-medium">
                        Reverse run
                    </button>
                </form>
            @endunless
        </div>
    </div>

    @if (session('success'))
        <div class="mb-4 bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg">{{ session('success') }}</div>
    @endif
    @if (session('error'))
        <div class="mb-4 bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg">{{ session('error') }}</div>
    @endif

    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
        <div class="bg-white rounded-lg shadow p-4">
            <p class="text-xs text-gray-500 uppercase">Gross</p>
            <p class="text-xl font-bold text-gray-800">${{ number_format($totals['gross'], 2) }}</p>
        </div>
        <div class="bg-white rounded-lg shadow p-4">
            <p class="text-xs text-gray-500 uppercase">PAYG withheld</p>
            <p class="text-xl font-bold text-gray-800">${{ number_format($totals['payg'], 2) }}</p>
        </div>
        <div class="bg-white rounded-lg shadow p-4">
            <p class="text-xs text-gray-500 uppercase">Super</p>
            <p class="text-xl font-bold text-gray-800">${{ number_format($totals['super'], 2) }}</p>
        </div>
        <div class="bg-white rounded-lg shadow p-4">
            <p class="text-xs text-gray-500 uppercase">Net paid</p>
            <p class="text-xl font-bold text-gray-800">${{ number_format($totals['net'], 2) }}</p>
        </div>
    </div>

    <div class="bg-white rounded-lg shadow overflow-hidden mb-6">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Payee</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Hours</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Gross</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">PAYG</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Super</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Net</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Flags</th>
                        @unless($run->isProcessed()) <th class="px-4 py-3"></th> @endunless
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    @forelse ($run->payslips as $payslip)
                        <tr>
                            <td class="px-4 py-3 font-medium text-gray-900">{{ $payslip->employee->name }}</td>
                            <td class="px-4 py-3 text-right text-gray-600">{{ $payslip->hours !== null ? number_format((float) $payslip->hours, 2) : '—' }}</td>
                            <td class="px-4 py-3 text-right text-gray-900">${{ number_format((float) $payslip->gross, 2) }}</td>
                            <td class="px-4 py-3 text-right text-gray-600">${{ number_format((float) $payslip->payg_withheld, 2) }}</td>
                            <td class="px-4 py-3 text-right text-gray-600">${{ number_format((float) $payslip->super, 2) }}</td>
                            <td class="px-4 py-3 text-right font-medium text-gray-900">${{ number_format((float) $payslip->net_pay, 2) }}</td>
                            <td class="px-4 py-3">
                                @if($payslip->is_closely_linked)
                                    <span class="px-2 py-0.5 text-xs rounded-full bg-purple-100 text-purple-800 mr-1" title="Related to directors/shareholders — FBT / PSI attention">closely linked</span>
                                @endif
                                @if($payslip->is_personal_services)
                                    <span class="px-2 py-0.5 text-xs rounded-full bg-orange-100 text-orange-800" title="Paid for an individual's personal exertion — personal services income rules">PSI</span>
                                @endif
                            </td>
                            @unless($run->isProcessed())
                                <td class="px-4 py-3 text-right">
                                    <form method="POST" action="{{ route('payroll.runs.payslips.destroy', [$run, $payslip]) }}">
                                        @csrf @method('DELETE')
                                        <button class="text-red-600 hover:text-red-800 text-xs font-medium">Remove</button>
                                    </form>
                                </td>
                            @endunless
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ $run->isProcessed() ? 7 : 8 }}" class="px-4 py-8 text-center text-gray-500">
                                No payslips yet — add one below.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @unless($run->isProcessed())
        @if($employees->isNotEmpty())
            <form method="POST" action="{{ route('payroll.runs.payslips.store', $run) }}"
                class="bg-white rounded-lg shadow p-6 grid grid-cols-1 md:grid-cols-5 gap-3 items-end">
                @csrf
                <div class="md:col-span-2">
                    <label class="block text-xs font-medium text-gray-500 mb-1">Payee</label>
                    <select name="employee_id" required class="w-full border-gray-300 rounded-lg text-sm">
                        @foreach ($employees as $employee)
                            <option value="{{ $employee->id }}">
                                {{ $employee->name }}
                                ({{ $employee->payment_basis === 'salary'
                                    ? '$'.number_format((float) $employee->annual_salary, 0).' / year'
                                    : ($employee->hourly_rate !== null ? '$'.rtrim(rtrim(number_format((float) $employee->hourly_rate, 4), '0'), '.').'/hr' : 'no rate') }})
                            </option>
                        @endforeach
                    </select>
                    @error('employee_id') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-500 mb-1">Hours (hourly staff)</label>
                    <input type="number" name="hours" step="0.25" min="0" value="{{ old('hours') }}"
                        class="w-full border-gray-300 rounded-lg text-sm">
                    @error('hours') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-500 mb-1">Gross override</label>
                    <input type="number" name="gross" step="0.01" min="0" value="{{ old('gross') }}"
                        placeholder="leave blank for hours × rate / salary"
                        class="w-full border-gray-300 rounded-lg text-sm">
                    @error('gross') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <button class="w-full px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm hover:bg-indigo-700">
                        Add payslip
                    </button>
                </div>
            </form>
        @else
            <p class="text-sm text-gray-500">
                Every active payee is already on this run.
            </p>
        @endif
    @else
        <p class="text-sm text-gray-500">
            Processed {{ $run->processed_at?->format('d M Y H:i') }} — journals posted
            (wages accrual, super accrual, net payment). Reverse the run to edit it.
        </p>
    @endunless
@endsection
