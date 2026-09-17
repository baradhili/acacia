@extends('layouts.app')
@section('title', 'New Lead')
@section('content')
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-800">New Lead</h1>
    </div>

    <form action="{{ route('crm.leads.store') }}" method="POST" class="bg-white rounded-lg shadow p-6 max-w-3xl">
        @csrf
        @include('crm.leads.form', ['lead' => null])
        <div class="flex justify-end mt-6">
            <a href="{{ route('crm.leads.index') }}" class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-6 py-2 rounded-lg mr-3">Cancel</a>
            <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white px-6 py-2 rounded-lg">Create Lead</button>
        </div>
    </form>
@endsection
