@extends('layouts.app')
@section('title', 'Employee Reimbursements')
@section('content')

    <div class="mb-6 flex justify-between items-center">
        <h1 class="text-2xl font-bold text-gray-800">Employee Reimbursements</h1>
        <a href="{{ route('reimbursement-payments.create') }}" class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg">
            + Pay Reimbursement
        </a>
    </div>

    @if ($outstanding->isNotEmpty())
        <div class="bg-white rounded-lg shadow p-4 mb-6">
            <h2 class="text-sm font-semibold text-gray-500 uppercase mb-3">Owed to employees</h2>
            <div class="flex flex-wrap gap-3">
                @foreach ($outstanding as $employeeId => $amount)
                    <a href="{{ route('reimbursement-payments.create', ['employee_id' => $employeeId]) }}"
                        class="inline-flex items-center gap-2 bg-amber-50 border border-amber-200 hover:bg-amber-100 rounded-lg px-4 py-2">
                        <span class="font-medium text-gray-800">{{ $employees[$employeeId] }}</span>
                        <span class="font-semibold text-amber-700">${{ number_format($amount, 2) }}</span>
                    </a>
                @endforeach
            </div>
        </div>
    @endif

    <!-- Filters -->
    <div class="bg-white rounded-lg shadow p-4 mb-6">
        <form method="GET" class="flex gap-4 items-end">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Employee</label>
                <select name="employee_id" class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                    <option value="">All Employees</option>
                    @foreach ($employees as $id => $name)
                        <option value="{{ $id }}" {{ request('employee_id') == $id ? 'selected' : '' }}>{{ $name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                <select name="status" class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                    <option value="">All</option>
                    <option value="completed" {{ request('status') === 'completed' ? 'selected' : '' }}>Completed</option>
                    <option value="void" {{ request('status') === 'void' ? 'selected' : '' }}>Void</option>
                </select>
            </div>
            <button type="submit" class="bg-gray-800 text-white px-4 py-2 rounded-lg hover:bg-gray-700">
                Filter
            </button>
            <a href="{{ route('reimbursement-payments.index') }}" class="text-gray-600 hover:text-gray-800 px-4 py-2">Clear</a>
        </form>
    </div>

    <div class="bg-white rounded-lg shadow overflow-hidden">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Payment #</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Employee</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Method</th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Amount</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Reference</th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                @forelse ($payments as $payment)
                    <tr class="{{ $payment->status === 'void' ? 'opacity-50' : '' }}">
                        <td class="px-6 py-4">
                            <a href="{{ route('reimbursement-payments.show', $payment) }}" class="text-indigo-600 hover:text-indigo-900 font-medium">
                                {{ $payment->payment_number }}
                            </a>
                            @if ($payment->status === 'void')
                                <span class="ml-1 px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-gray-100 text-gray-500">
                                    Void
                                </span>
                            @endif
                        </td>
                        <td class="px-6 py-4 text-gray-900">{{ $payment->employee?->name ?? '-' }}</td>
                        <td class="px-6 py-4 text-gray-900">{{ $payment->payment_date->format('d M Y') }}</td>
                        <td class="px-6 py-4 text-gray-900">{{ $payment->formatted_method }}</td>
                        <td class="px-6 py-4 text-right text-gray-900">${{ number_format($payment->amount, 2) }}</td>
                        <td class="px-6 py-4 text-gray-900">{{ Str::limit($payment->reference ?? '-', 20) }}</td>
                        <td class="px-6 py-4 text-right">
                            <a href="{{ route('reimbursement-payments.show', $payment) }}" class="text-indigo-600 hover:text-indigo-900 mr-3">View</a>
                            @if ($payment->status !== 'void')
                                <form action="{{ route('reimbursement-payments.void', $payment) }}" method="POST" class="inline"
                                    onsubmit="return confirm('Void this reimbursement? Its ledger entry is reversed and the employee is owed the amount again.');">
                                    @csrf
                                    <button type="submit" class="text-red-600 hover:text-red-900">Void</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-6 py-4 text-center text-gray-500">No reimbursements yet.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $payments->links() }}
    </div>

@endsection
