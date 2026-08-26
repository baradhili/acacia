@props([
    'name' => 'country',
    'value' => null,
    'label' => 'Country',
])

@php
    [$pinnedCountries, $otherCountries] = \App\Services\Countries::dropdown();
    $selected = old($name, $value);
    // Legacy values not in the list (e.g. historic free-text entries)
    // stay selectable so editing an old record never silently wipes them.
    $isKnown = $selected === null || $selected === '' || \App\Services\Countries::exists($selected);
@endphp
<div>
    <label for="{{ $name }}" class="block text-sm font-medium text-gray-700 mb-1">{{ $label }}</label>
    <select name="{{ $name }}" id="{{ $name }}"
            class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
        <option value="" @selected((string) $selected === '')>Select Country</option>
        @foreach ($pinnedCountries as $country)
            <option value="{{ $country }}" @selected((string) $selected === $country)>{{ $country }}</option>
        @endforeach
        @unless ($isKnown)
            <option value="{{ $selected }}" selected>{{ $selected }}</option>
        @endunless
        <optgroup label="All countries">
            @foreach ($otherCountries as $country)
                <option value="{{ $country }}" @selected((string) $selected === $country)>{{ $country }}</option>
            @endforeach
        </optgroup>
    </select>
</div>
