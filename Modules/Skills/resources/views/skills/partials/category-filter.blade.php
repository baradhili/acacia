@php
    // Client-side category filter for the skill matrices. Rendered
    // inside the parent's x-data scope: rows carry data-category and
    // toggle with the select. Hidden rows still submit their ticks —
    // the filter is a view aid, never a way to drop selections.
    //
    // Option values are namespaced ('c:' + category) so no real
    // category — one literally named __none, or the falsy-looking
    // '0' that a bare filter() would drop — can collide with the
    // All ('') and Uncategorised ('__none') sentinels. Rows carry
    // the same namespaced key; the match is by identity, never by
    // the raw string.
    $categories = $skills
        ->pluck('category')
        ->filter(fn ($category) => filled($category))
        ->unique()
        ->sort()
        ->values();
@endphp
@if ($categories->isNotEmpty())
    <div class="flex items-center justify-between gap-4 px-4 py-3 bg-gray-50 border-b border-gray-200">
        <label for="skill-category-filter" class="text-xs font-medium text-gray-500 uppercase">Filter by category</label>
        <select id="skill-category-filter" x-model="category"
            class="border-gray-300 rounded-md text-sm focus:border-indigo-500 focus:ring-indigo-500">
            <option value="">All categories</option>
            @foreach ($categories as $category)
                <option value="c:{{ $category }}">{{ $category }}</option>
            @endforeach
            @if ($skills->contains(fn ($skill) => blank($skill->category)))
                <option value="__none">Uncategorised</option>
            @endif
        </select>
    </div>
@endif
