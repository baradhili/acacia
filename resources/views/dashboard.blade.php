@extends('layouts.app')
@section('title', __('widgets.title'))

@section('content')
<div x-data="widgetManager()" @toggle-widget-edit.window="toggleEdit()">
    @if ($unclosedPriorYear ?? null)
        <!-- Year-end close nudge -->
        <div class="mb-4 p-4 bg-amber-50 border border-amber-200 rounded-lg flex justify-between items-center">
            <p class="text-amber-800 text-sm">
                <strong>Action needed:</strong> financial year {{ $unclosedPriorYear }} has ended but hasn't been
                closed. Run the year-end close to move its profit into Retained Earnings and lock the year.
            </p>
            <a href="{{ route('financial-years.trial', $unclosedPriorYear) }}"
                class="ml-4 shrink-0 px-3 py-1.5 bg-amber-600 text-white text-sm rounded hover:bg-amber-700">
                Run trial close
            </a>
        </div>
    @endif

    <!-- Edit Mode toolbar: toggled from the profile menu's Customize
         Dashboard item or the Done button; leaving edit mode saves. -->
    <div x-show="isEditing" x-cloak class="mb-4 p-4 bg-blue-50 border border-blue-200 rounded-lg">
        <div class="flex flex-wrap justify-between items-center gap-3">
            <p class="text-blue-800 text-sm">
                <strong>{{ __('widgets.editing_title') }}:</strong> {{ __('widgets.edit_instructions') }}
            </p>
            <div class="flex gap-2 shrink-0">
                <button type="button" @click="resetLayout($event)" data-confirm="{{ __('widgets.reset_confirm') }}"
                    class="px-3 py-1 border border-blue-300 text-blue-700 text-sm rounded hover:bg-blue-100">
                    {{ __('widgets.reset') }}
                </button>
                <button type="button" @click="toggleEdit()" :disabled="saving"
                    class="px-3 py-1 bg-blue-600 text-white text-sm rounded hover:bg-blue-700 disabled:opacity-50">
                    {{ __('widgets.done') }}
                </button>
            </div>
        </div>
        <p x-show="saveError" x-cloak class="mt-2 text-sm text-red-700">{{ __('widgets.save_failed') }}</p>
    </div>

    <!-- Widget grid — the viewer's saved layout over the widget
         registry (core + module contributions); ids match the
         preference rows' widget_name keys. -->
    <div id="widget-grid" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
        @foreach ($dashboardWidgets as $widget)
            @include('dashboard.widget-card', ['widget' => $widget])
        @endforeach
    </div>

    <!-- Removed widgets: cards park in the hidden store, the catalog
         lists them; edit mode's Add moves the card back onto the
         grid. Server-rendered rows cover the saved layout, the
         template covers widgets removed during this session. -->
    <div id="widget-store" class="hidden" aria-hidden="true">
        @foreach ($hiddenWidgets as $widget)
            @include('dashboard.widget-card', ['widget' => $widget])
        @endforeach
    </div>

    <div id="widget-catalog" x-show="isEditing" x-cloak class="mt-6">
        <h3 class="text-sm font-semibold text-gray-700 mb-2">{{ __('widgets.available') }}</h3>
        <p id="catalog-empty" class="text-sm text-gray-500{{ count($hiddenWidgets) === 0 ? '' : ' hidden' }}">
            {{ __('widgets.all_shown') }}
        </p>
        <div id="widget-catalog-rows" class="flex flex-wrap gap-2">
            @foreach ($hiddenWidgets as $widget)
                <div class="catalog-entry flex items-center gap-3 pl-3 pr-1 py-1 bg-white border border-gray-200 rounded shadow-sm"
                    data-widget="{{ $widget['id'] }}">
                    <span class="text-sm text-gray-700">{{ __($widget['label']) }}</span>
                    <button type="button" @click="addWidget($el.closest('.catalog-entry'))"
                        class="px-2 py-1 bg-blue-600 text-white text-xs rounded hover:bg-blue-700">{{ __('widgets.add') }}</button>
                </div>
            @endforeach
        </div>
    </div>

    <template id="catalog-row-template">
        <div class="catalog-entry flex items-center gap-3 pl-3 pr-1 py-1 bg-white border border-gray-200 rounded shadow-sm">
            <span class="text-sm text-gray-700"></span>
            <button type="button" @click="addWidget($el.closest('.catalog-entry'))"
                class="px-2 py-1 bg-blue-600 text-white text-xs rounded hover:bg-blue-700">{{ __('widgets.add') }}</button>
        </div>
    </template>
</div>
@endsection

@push('styles')
<style>
[x-cloak] {
    display: none !important;
}
.widget-card {
    transition: all 0.2s;
}
.widget-card:hover {
    z-index: 10;
}
.widget-card.sortable-ghost {
    opacity: 0.4;
}
.widget-handle {
    cursor: grab;
}
.widget-handle:active {
    cursor: grabbing;
}
</style>
@endpush
