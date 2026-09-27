@extends('layouts.app')
@section('title', $employee->name.' — payee')
@section('content')

    <div class="mb-6 flex justify-between items-start">
        <div>
            <h1 class="text-2xl font-bold text-gray-800">{{ $employee->name }}</h1>
            <p class="text-sm text-gray-500 mt-1">
                {{ ucfirst($employee->employment_type) }} ·
                <span class="px-2 py-1 text-xs font-medium rounded-full
                    {{ $employee->status === 'active' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-600' }}">
                    {{ ucfirst($employee->status) }}
                </span>
                @if ($employee->start_date)
                    · since {{ $employee->start_date->format('M Y') }}
                @endif
            </p>
        </div>
        <div class="flex gap-2">
            <a href="{{ route('payroll.employees.index') }}"
                class="px-3 py-2 border border-gray-300 rounded-lg text-gray-700 text-sm hover:bg-gray-50">All payees</a>
            <a href="{{ route('payroll.employees.edit', $employee) }}"
                class="px-3 py-2 bg-indigo-600 text-white rounded-lg text-sm hover:bg-indigo-700">Edit payee</a>
        </div>
    </div>

    <div class="bg-white rounded-lg shadow p-6 max-w-3xl">
        <h2 class="text-base font-semibold text-gray-800 mb-4">Payroll details</h2>
        <dl class="grid grid-cols-1 md:grid-cols-2 gap-x-6 gap-y-4 text-sm">
            <div>
                <dt class="text-xs font-medium text-gray-500 uppercase">Email</dt>
                <dd class="mt-1 text-gray-900">{{ $employee->email ?: '—' }}</dd>
            </div>
            <div>
                <dt class="text-xs font-medium text-gray-500 uppercase">Linked login</dt>
                <dd class="mt-1 text-gray-900">
                    @if ($employee->user)
                        {{ $employee->user->name }} <span class="text-gray-500">({{ $employee->user->email }})</span>
                    @else
                        <span class="text-gray-400">no linked user</span>
                    @endif
                </dd>
            </div>
            <div>
                <dt class="text-xs font-medium text-gray-500 uppercase">TFN</dt>
                <dd class="mt-1 text-gray-900">
                    {{-- the 47% no-TFN rate is a withholding rule: it only threatens payees PAYG applies to --}}
                    @if ($employee->withholdsPayg())
                        {{ $employee->tfn ?: 'NO TFN — 47% withholding' }}
                    @else
                        {{ $employee->tfn ?: 'not on file' }}
                    @endif
                </dd>
            </div>
            <div>
                <dt class="text-xs font-medium text-gray-500 uppercase">Tax treatment</dt>
                <dd class="mt-1 text-gray-900">
                    @if ($employee->withholdsPayg())
                        Scale {{ $employee->taxScale() }} ·
                        {{ $employee->tax_free_threshold ? 'claiming' : 'not claiming' }} the tax-free threshold
                    @else
                        No PAYG withholding — the contractor handles their own tax
                    @endif
                </dd>
            </div>
            <div>
                <dt class="text-xs font-medium text-gray-500 uppercase">Payment basis</dt>
                <dd class="mt-1 text-gray-900">
                    @if ($employee->payment_basis === 'salary')
                        Salary — ${{ number_format((float) $employee->annual_salary, 0) }}/yr
                    @elseif ($employee->hourly_rate !== null)
                        Hourly — ${{ rtrim(rtrim(number_format((float) $employee->hourly_rate, 4), '0'), '.') }}/hr
                    @else
                        —
                    @endif
                </dd>
            </div>
            <div>
                <dt class="text-xs font-medium text-gray-500 uppercase">Super</dt>
                <dd class="mt-1 text-gray-900">
                    @if ($employee->super_rate !== null)
                        {{-- stored as a fraction (0.115 = 11.5%): PayrollService multiplies earnings by it --}}
                        {{ rtrim(rtrim(number_format((float) $employee->super_rate * 100, 2), '0'), '.') }}%
                        <span class="text-gray-500">· custom rate</span>
                    @else
                        {{-- null = the SG rate in force at pay time; today's shown --}}
                        {{ rtrim(rtrim(number_format($sgRate * 100, 2), '0'), '.') }}%
                        <span class="text-gray-500">· super guarantee default</span>
                    @endif
                    @if ($employee->super_fund)
                        <span class="text-gray-500">· {{ $employee->super_fund }}</span>
                    @endif
                    @if ($employee->super_member_id)
                        <span class="text-gray-500">· {{ $employee->super_member_id }}</span>
                    @endif
                </dd>
            </div>
            <div>
                <dt class="text-xs font-medium text-gray-500 uppercase">Flags</dt>
                <dd class="mt-1 space-x-1">
                    @if ($employee->is_closely_linked)
                        <span class="px-2 py-0.5 text-xs rounded-full bg-purple-100 text-purple-800">closely linked</span>
                    @endif
                    @if ($employee->is_personal_services)
                        <span class="px-2 py-0.5 text-xs rounded-full bg-orange-100 text-orange-800">PSI</span>
                    @endif
                    @if ($employee->labour_only)
                        <span class="px-2 py-0.5 text-xs rounded-full bg-blue-100 text-blue-800">labour only (SG)</span>
                    @endif
                    @unless ($employee->is_closely_linked || $employee->is_personal_services || $employee->labour_only)
                        <span class="text-gray-400">none</span>
                    @endunless
                </dd>
            </div>
            <div>
                <dt class="text-xs font-medium text-gray-500 uppercase">Employment period</dt>
                <dd class="mt-1 text-gray-900">
                    {{ $employee->start_date?->format('d M Y') ?? '—' }} →
                    {{ $employee->end_date?->format('d M Y') ?? 'ongoing' }}
                </dd>
            </div>
            @if ($employee->notes)
                <div class="md:col-span-2">
                    <dt class="text-xs font-medium text-gray-500 uppercase">Notes</dt>
                    <dd class="mt-1 text-gray-900 whitespace-pre-line">{{ $employee->notes }}</dd>
                </div>
            @endif
        </dl>
    </div>

    @if (\Route::has('skills.employees.show'))
        @include('skills::partials.payee-skills', ['employee' => $employee])
    @endif

    @if (\Route::has('resumes.index'))
        @include('resumes::partials.payee-resumes', ['employee' => $employee])
    @endif
@endsection
