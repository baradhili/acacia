@extends('layouts.app')
@section('title', 'Apply Credit Note')
@section('content')

    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-800">Apply Credit Note</h1>
        <p class="text-gray-600">Invoice {{ $invoice->invoice_number }}</p>
    </div>

    <div class="bg-white rounded-lg shadow p-6 max-w-2xl">
        <form action="{{ route('credit-notes.applyToInvoice', $creditNotes->keys()->first() ?? 0) }}" method="POST">
            @csrf
            <input type="hidden" name="invoice_id" value="{{ $invoice->id }}">

            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-1">Credit Note</label>
                <select name="credit_note_id" id="credit_note_id"
                    class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 w-full">
                    @foreach($creditNotes as $id => $number)
                        <option value="{{ $id }}">{{ $number }}</option>
                    @endforeach
                </select>
            </div>

            <div class="flex justify-end gap-4">
                <a href="{{ route('invoices.show', $invoice) }}" class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-6 py-2 rounded-lg">Cancel</a>
                <button type="submit" name="Apply" class="bg-indigo-600 hover:bg-indigo-700 text-white px-6 py-2 rounded-lg">Apply</button>
            </div>
        </form>
    </div>

@endsection
