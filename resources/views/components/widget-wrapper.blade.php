@props(['widgetName' => ''])
<div class="grid-stack-item" data-widget="{{ $widgetName }}">
    <div class="grid-stack-item-content widget-content">
        <div class="bg-white rounded-lg shadow h-full flex flex-col">
            {{ $slot }}
        </div>
    </div>
</div>
