@extends('layouts.app')
@section('title', 'Edit '.$declaration->declaration_number)
@section('content')

    <div class="mb-6 flex justify-between items-center">
        <h1 class="text-2xl font-bold text-gray-800">Edit {{ $declaration->declaration_number }}</h1>
        <a href="{{ route('dividends.show', $declaration) }}" class="text-sm text-indigo-600 hover:underline">← Back</a>
    </div>

    <div class="bg-white rounded-lg shadow p-6 max-w-3xl">
        <form method="POST" action="{{ route('dividends.update', $declaration) }}">
            @csrf @method('PUT')
            @include('dividends.form')
            <div class="mt-6 flex gap-3">
                <button class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg text-sm font-medium">
                    Save changes
                </button>
                <a href="{{ route('dividends.show', $declaration) }}"
                    class="px-4 py-2 rounded-lg text-sm font-medium text-gray-600 hover:bg-gray-100">Cancel</a>
            </div>
        </form>
    </div>
@endsection
