@extends('layouts.app')
@section('title', 'Payroll employees')
@section('content')

    <div class="mb-6 flex justify-between items-center">
        <div>
            <h1 class="text-2xl font-bold text-gray-800">Payroll employees</h1>
            <p class="text-sm text-gray-500 mt-1">
                Pay basis, tax treatment (TFN + the tax-free threshold picks the ATO withholding scale),
                super, and the closely-linked / personal-services markers.
            </p>
        </div>
        <a href="{{ route('payroll.employees.create') }}"
            class="px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 text-sm">Add payee</a>
    </div>

    @if (session('success'))
        <div class="mb-4 bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg">{{ session('success') }}</div>
    @endif
    @if (session('error'))
        <div class="mb-4 bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg">{{ session('error') }}</div>
    @endif

    <div class="bg-white rounded-lg shadow overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Payee</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Type</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Basis</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Tax</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Flags</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    @forelse ($employees as $employee)
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3">
                                <div class="font-medium text-gray-900">{{ $employee->name }}</div>
                                <div class="text-xs text-gray-500">{{ $employee->email ?: '—' }}</div>
                            </td>
                            <td class="px-4 py-3 text-gray-600">{{ ucfirst($employee->employment_type) }}</td>
                            <td class="px-4 py-3 text-gray-600">
                                @if($employee->payment_basis === 'salary')
                                    ${{ number_format((float) $employee->annual_salary, 0) }}/yr
                                @elseif($employee->hourly_rate !== null)
                                    ${{ rtrim(rtrim(number_format((float) $employee->hourly_rate, 4), '0'), '.') }}/hr
                                @else
                                    —
                                @endif
                            </td>
                            <td class="px-4 py-3 text-gray-600">
                                {{ $employee->tfn ? 'TFN on file' : 'NO TFN — 47%' }} ·
                                scale {{ $employee->taxScale() }}
                            </td>
                            <td class="px-4 py-3">
                                @if($employee->is_closely_linked)
                                    <span class="px-2 py-0.5 text-xs rounded-full bg-purple-100 text-purple-800 mr-1">closely linked</span>
                                @endif
                                @if($employee->is_personal_services)
                                    <span class="px-2 py-0.5 text-xs rounded-full bg-orange-100 text-orange-800">PSI</span>
                                @endif
                            </td>
                            <td class="px-4 py-3">
                                <span class="px-2 py-1 text-xs font-medium rounded-full
                                    {{ $employee->status === 'active' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-600' }}">
                                    {{ ucfirst($employee->status) }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-right space-x-3">
                                <a href="{{ route('payroll.employees.edit', $employee) }}"
                                    class="text-indigo-600 hover:text-indigo-900 font-medium">Edit</a>
                                <form method="POST" action="{{ route('payroll.employees.destroy', $employee) }}" class="inline">
                                    @csrf @method('DELETE')
                                    <button class="text-red-600 hover:text-red-800 font-medium">Delete</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-4 py-8 text-center text-gray-500">No payees yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
