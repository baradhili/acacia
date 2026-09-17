@extends('layouts.app')
@section('title', 'Edit Lead')
@section('content')
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-800">Edit Lead — {{ $lead->name }}</h1>
    </div>

    <form action="{{ route('crm.leads.update', $lead) }}" method="POST" class="bg-white rounded-lg shadow p-6 max-w-3xl">
        @csrf
        @method('PUT')
        @include('crm.leads.form', ['lead' => $lead])
        <div class="flex justify-end mt-6">
            <a href="{{ route('crm.leads.show', $lead) }}" class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-6 py-2 rounded-lg mr-3">Cancel</a>
            <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white px-6 py-2 rounded-lg">Update Lead</button>
        </div>
    </form>
@endsection
