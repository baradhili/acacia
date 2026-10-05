@extends('layouts.app')
@section('title', __('reconciliation.import.title'))
@section('content')

    <div class="mb-6 flex items-center">
        <a href="{{ route('reconciliation.index') }}" class="text-gray-500 hover:text-gray-700 mr-4">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
            </svg>
        </a>
        <h1 class="text-2xl font-bold text-gray-800">{{ __('reconciliation.import.title') }}</h1>
    </div>

    <div class="bg-white rounded-lg shadow p-6">
        <form action="{{ route('reconciliation.process-import') }}" method="POST" enctype="multipart/form-data">
            @csrf
            <div class="mb-6">
                <label class="block text-sm font-medium text-gray-700 mb-2">
                    {{ __('reconciliation.import.heading') }}
                </label>
                <div class="mt-1 flex justify-center px-6 pt-5 pb-6 border-2 border-gray-300 border-dashed rounded-lg hover:border-gray-400 transition-colors">
                    <div class="space-y-1 text-center">
                        <svg class="mx-auto h-12 w-12 text-gray-400" stroke="currentColor" fill="none" viewBox="0 0 48 48">
                            <path d="M28 8H12a4 4 0 00-4 4v20m32-12v8m0 0v8a4 4 0 01-4 4H12a4 4 0 01-4-4v-4m32-4l-3.172-3.172a4 4 0 00-5.656 0L28 28M8 32l9.172-9.172a4 4 0 015.656 0L28 28m0 0l4 4m4-24h8m-4-4v8m-12 4h.02" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                        <div class="flex text-sm text-gray-600">
                            <label for="statement" class="relative cursor-pointer bg-white rounded-md font-medium text-blue-600 hover:text-blue-500 focus-within:outline-hidden focus-within:ring-2 focus-within:ring-offset-2 focus-within:ring-blue-500">
                                <span>{{ __('reconciliation.import.upload_label') }}</span>
                                <input id="statement" name="statement" type="file" class="sr-only" accept=".csv,.txt,.xml,.mt940,.camt,.sta" required>
                            </label>
                            <p class="pl-1">{{ __('reconciliation.import.drop_hint') }}</p>
                        </div>
                        <p class="text-xs text-gray-500">{{ __('reconciliation.import.file_hint') }}</p>
                    </div>
                </div>
                @error('statement')
                    <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-6">
                <h3 class="text-sm font-medium text-blue-800">{{ __('reconciliation.import.formats_title') }}</h3>
                <p class="text-sm text-blue-700 mt-1">
                    {{ __('reconciliation.import.formats_body') }}
                </p>
            </div>

            <div class="flex justify-end gap-3">
                <a href="{{ route('reconciliation.index') }}" class="px-4 py-2 text-gray-700 hover:text-gray-900">{{ __('reconciliation.import.cancel') }}</a>
                <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg">
                    {{ __('reconciliation.import.submit') }}
                </button>
            </div>
        </form>
    </div>

@endsection
