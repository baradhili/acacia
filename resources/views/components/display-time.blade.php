@props([
    // The stored-UTC timestamp to render; null renders the fallback.
    'time' => null,
    'fallback' => '-',
])

{{-- The screen stamp: the server-rendered text (configured display
     zone, PHP-abbreviated) inside a <time> element carrying the UTC
     instant, which resources/js/display-time.js re-renders in the
     viewer's own browser timezone. Every value escapes through {{ }}
     — there is no raw markup anywhere in the chain. --}}
@if ($time)
    <time datetime="{{ $time->copy()->utc()->toIso8601String() }}" data-display-time>{{ \App\Support\DisplayTime::format($time) }}</time>
@else
    {{ $fallback }}
@endif
