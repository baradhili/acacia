@extends('layouts.app')
@section('title', 'Dashboard')

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

    <!-- Edit Mode Instructions -->
    <div x-show="isEditing" class="mb-4 p-4 bg-blue-50 border border-blue-200 rounded-lg flex justify-between items-center">
        <p class="text-blue-800 text-sm">
            <strong>Edit Mode:</strong> Drag widgets by their header to reposition. 
            Click "Done" in the profile menu to save.
        </p>
        <button 
            @click="toggleEdit()"
            class="px-3 py-1 bg-blue-600 text-white text-sm rounded hover:bg-blue-700"
        >
            Done
        </button>
    </div>

    <!-- Widget Grid — renders the widget registry (core + module
         contributions); ids match the drag-order preferences keys. -->
    <div id="widget-grid" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
        @foreach ($dashboardWidgets as $widget)
            <div class="widget-card {{ $widget['span'] }}" data-widget="{{ $widget['id'] }}">@widget($widget['class'])</div>
        @endforeach
    </div>
</div>
@endsection

@push('styles')
<style>
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