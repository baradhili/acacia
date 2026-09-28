{{--
    One dashboard widget card, as rendered into both the grid and the
    hidden store (removed widgets wait there so edit mode can add them
    back by moving the node, with no server round trip). The data-*
    attributes are widget-manager.js's layout state: width (0 = the
    registry's shipped span in data-default-span) and the label for
    the catalog row created on removal.
--}}
<div class="widget-card relative {{ $widget['span'] }}"
    data-widget="{{ $widget['id'] }}"
    data-label="{{ __($widget['label']) }}"
    data-width="{{ $widget['width'] }}"
    data-default-span="{{ $widget['default_span'] }}">
    @widget($widget['class'])
    <div x-show="isEditing" x-cloak class="absolute top-2 right-2 z-20 flex gap-1">
        <button type="button" @click="cycleWidth($el.closest('.widget-card'))"
            title="{{ __('widgets.resize') }}"
            class="w-7 h-7 flex items-center justify-center rounded bg-white/90 border border-gray-300 text-gray-600 text-sm shadow-sm hover:bg-gray-100"
            aria-label="{{ __('widgets.resize') }}">&harr;</button>
        <button type="button" @click="removeWidget($el.closest('.widget-card'))"
            title="{{ __('widgets.remove') }}"
            class="w-7 h-7 flex items-center justify-center rounded bg-white/90 border border-gray-300 text-red-600 text-sm shadow-sm hover:bg-red-50"
            aria-label="{{ __('widgets.remove') }}">&times;</button>
    </div>
</div>
