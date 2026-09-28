{{--
    The removed widgets' cards, served by the hidden-widgets endpoint
    and injected into #widget-store when edit mode first opens — the
    dashboard page itself ships only the catalog rows, so a hidden
    widget's queries never run on regular dashboard loads.
--}}
@foreach ($hiddenWidgets as $widget)
    @include('dashboard.widget-card', ['widget' => $widget])
@endforeach
