@extends('layouts.app')
@section('title', 'Reimbursement ' . $reimbursementPayment->payment_number)
@section('content')

    <div class="mb-6 flex justify-between items-center">
        <h1 class="text-2xl font-bold text-gray-800">Reimbursement {{ $reimbursementPayment->payment_number }}</h1>
        <div class="flex gap-4">
            <a href="{{ route('reimbursement-payments.index') }}" class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-4 py-2 rounded-lg">
                Back to Reimbursements
            </a>
            @if ($reimbursementPayment->status !== 'void')
                <form action="{{ route('reimbursement-payments.void', $reimbursementPayment) }}" method="POST"
                    onsubmit="return confirm('Void this reimbursement? Its ledger entry is reversed and the employee is owed the amount again.');">
                    @csrf
                    <button type="submit" class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-lg">
                        Void
                    </button>
                </form>
            @endif
        </div>
    </div>

    @if (session('success'))
        <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4">
            {{ session('success') }}
        </div>
    @endif
    @if (session('error'))
        <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
            {{ session('error') }}
        </div>
    @endif

    <div class="bg-white rounded-lg shadow p-6 mb-6">
        <h2 class="text-lg font-semibold text-gray-800 mb-4">Details</h2>
        <dl class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
                <dt class="text-sm font-medium text-gray-500">Employee</dt>
                <dd class="mt-1 text-gray-900">{{ $reimbursementPayment->employee?->name ?? '-' }}</dd>
            </div>
            <div>
                <dt class="text-sm font-medium text-gray-500">Amount</dt>
                <dd class="mt-1 text-gray-900 font-semibold">{{ $reimbursementPayment->formatted_amount }}</dd>
            </div>
            <div>
                <dt class="text-sm font-medium text-gray-500">Payment Date</dt>
                <dd class="mt-1 text-gray-900">{{ $reimbursementPayment->payment_date->format('d M Y') }}</dd>
            </div>
            <div>
                <dt class="text-sm font-medium text-gray-500">Method</dt>
                <dd class="mt-1 text-gray-900">{{ $reimbursementPayment->formatted_method }}</dd>
            </div>
            <div>
                <dt class="text-sm font-medium text-gray-500">Status</dt>
                <dd class="mt-1">
                    @if ($reimbursementPayment->status === 'void')
                        <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-gray-100 text-gray-500">Void</span>
                    @else
                        <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">
                            {{ ucfirst($reimbursementPayment->status) }}</span>
                    @endif
                </dd>
            </div>
            <div>
                <dt class="text-sm font-medium text-gray-500">Ledger</dt>
                <dd class="mt-1 text-gray-900">
                    @if ($reimbursementPayment->ifrs_transaction_id)
                        Posted (Dr Employee Reimbursements Payable / Cr Bank)
                    @else
                        Not yet posted
                    @endif
                </dd>
            </div>
            <div>
                <dt class="text-sm font-medium text-gray-500">Reference</dt>
                <dd class="mt-1 text-gray-900">{{ $reimbursementPayment->reference ?? '-' }}</dd>
            </div>
            <div>
                <dt class="text-sm font-medium text-gray-500">Recorded by</dt>
                <dd class="mt-1 text-gray-900">{{ $reimbursementPayment->payer?->name ?? '-' }}</dd>
            </div>
            <div class="md:col-span-2">
                <dt class="text-sm font-medium text-gray-500">Notes</dt>
                <dd class="mt-1 text-gray-900">{{ $reimbursementPayment->notes ?? '-' }}</dd>
            </div>
        </dl>
    </div>

    <x-document-upload :model="$reimbursementPayment" />

@endsection
