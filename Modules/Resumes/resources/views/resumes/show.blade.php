@extends('layouts.app')
@section('title', $resume->name.' — resume')
@section('content')

    @php
        // download links keep the current filter active
        $downloadParams = array_filter([
            'keywords' => $keywords,
            'match_all' => $matchAll ? '1' : null,
        ], fn ($value) => $value !== null && $value !== '');
    @endphp

    <div class="mb-6">
        <div class="flex justify-between items-start">
            <div>
                <h1 class="text-2xl font-bold text-gray-800">{{ $data['basics']['name'] ?? 'No name' }}</h1>
                <p class="text-sm text-gray-500 mt-1">
                    {{ $resume->employee?->name ?? '—' }} ·
                    uploaded {{ $resume->uploaded_at?->format('d M Y') }} by {{ $resume->uploadedBy?->name ?? '—' }}
                </p>
            </div>
            <div class="flex gap-2">
                <a href="{{ route('resumes.json', [$resume] + $downloadParams) }}"
                    class="px-3 py-2 border border-gray-300 rounded-lg text-gray-700 text-sm hover:bg-gray-50">JSON</a>
                <a href="{{ route('resumes.latex', [$resume] + $downloadParams) }}"
                    class="px-3 py-2 border border-gray-300 rounded-lg text-gray-700 text-sm hover:bg-gray-50">LaTeX</a>
                <a href="{{ route('resumes.docx', [$resume] + $downloadParams) }}"
                    class="px-3 py-2 border border-gray-300 rounded-lg text-gray-700 text-sm hover:bg-gray-50">DOCX</a>
                <a href="{{ route('resumes.pdf', [$resume] + $downloadParams) }}"
                    class="px-3 py-2 bg-indigo-600 text-white rounded-lg text-sm hover:bg-indigo-700">PDF</a>
            </div>
        </div>
    </div>

    @if (session('error'))
        <div class="mb-4 bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg">{{ session('error') }}</div>
    @endif

    <div class="mb-6 bg-white rounded-lg shadow p-4">
        <form method="GET" class="flex flex-wrap items-end gap-3">
            <div class="grow">
                <label class="block text-xs font-medium text-gray-500 uppercase mb-1">Tailor by keywords</label>
                <input type="text" name="keywords" value="{{ is_array($keywords) ? implode(', ', $keywords) : $keywords }}"
                    placeholder="e.g. payroll, reconciliation, gst"
                    class="w-full border-gray-300 rounded-lg text-sm">
            </div>
            <label class="flex items-center gap-2 text-sm text-gray-700 pb-2">
                <input type="checkbox" name="match_all" value="1" {{ $matchAll ? 'checked' : '' }}>
                Match all
            </label>
            <button class="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm hover:bg-indigo-700">Filter</button>
            @if ($isFiltered)
                <a href="{{ route('resumes.show', $resume) }}"
                    class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 text-sm hover:bg-gray-50">Clear</a>
            @endif
        </form>

        @if ($isFiltered && $metadata)
            <div class="mt-3 flex flex-wrap gap-2 text-xs">
                <span class="px-2 py-1 rounded-full bg-indigo-100 text-indigo-800 font-medium">
                    Filtered by: {{ is_array($keywords) ? implode(', ', $keywords) : $keywords }}{{ $matchAll ? ' (all must match)' : '' }}
                </span>
                @foreach (array_filter($metadata['removed_counts']) as $section => $removed)
                    <span class="px-2 py-1 rounded-full bg-gray-100 text-gray-600">
                        {{ ucfirst($section) }}: kept {{ $metadata['filtered_counts'][$section] }} of {{ $metadata['original_counts'][$section] }}
                    </span>
                @endforeach
                @if (! array_filter($metadata['removed_counts']))
                    <span class="px-2 py-1 rounded-full bg-green-100 text-green-800">Everything matched</span>
                @endif
            </div>
        @endif
    </div>

    <div class="bg-white rounded-lg shadow p-6 space-y-8 max-w-4xl">
        {{-- Basics header --}}
        <div>
            <div class="text-xl font-bold text-gray-900">{{ $data['basics']['name'] ?? 'No name' }}</div>
            @if (! empty($data['basics']['label']))
                <div class="text-sm text-gray-500 italic">{{ $data['basics']['label'] }}</div>
            @endif
            <div class="text-sm text-gray-500 mt-1">
                @foreach (array_filter([
                    $data['basics']['email'] ?? null,
                    $data['basics']['phone'] ?? null,
                    collect($data['basics']['location'] ?? [])->only(['city', 'region', 'countryCode'])->filter()->implode(', '),
                ]) as $line)
                    <span>{{ $line }}</span> <span class="mx-1">·</span>
                @endforeach
                @foreach ($data['basics']['links'] ?? [] as $link)
                    <a href="{{ $link['url'] }}" target="_blank" rel="noopener" class="text-indigo-600 hover:underline">{{ $link['label'] ?? $link['url'] }}</a>
                    <span class="mx-1">·</span>
                @endforeach
            </div>
            @if (! empty($data['basics']['summary']))
                <p class="text-sm text-gray-600 mt-3">{{ $data['basics']['summary'] }}</p>
            @endif
        </div>

        {{-- Skills --}}
        @if (! empty($data['skills']))
            <div>
                <h2 class="text-sm font-semibold text-gray-500 uppercase tracking-wide border-b border-gray-200 pb-1 mb-3">Skills</h2>
                <div class="flex flex-wrap gap-2">
                    @foreach ($data['skills'] as $skill)
                        <span class="px-3 py-1 rounded-full bg-gray-100 text-gray-700 text-sm">
                            {{ $skill['name'] }}@if (! empty($skill['level'])) <span class="text-gray-400">({{ $skill['level'] }})</span>@endif
                            @if (! empty($skill['keywords'])) <span class="text-gray-400">— {{ implode(', ', $skill['keywords']) }}</span>@endif
                        </span>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- Work --}}
        @if (! empty($data['work']))
            <div>
                <h2 class="text-sm font-semibold text-gray-500 uppercase tracking-wide border-b border-gray-200 pb-1 mb-3">Work experience</h2>
                <div class="space-y-5">
                    @foreach ($data['work'] as $job)
                        <div>
                            <div class="flex justify-between items-baseline">
                                <div class="font-medium text-gray-900">{{ $job['position'] ?? '' }}</div>
                                <div class="text-xs text-gray-500 whitespace-nowrap ml-4">{{ $job['startDate'] ?? '' }} — {{ $job['endDate'] ?? 'Present' }}</div>
                            </div>
                            <div class="text-sm text-gray-500">
                                {{ $job['name'] ?? $job['employer'] ?? '' }}@if (! empty($job['location'])), {{ $job['location'] }}@endif
                            </div>
                            @if (! empty($job['summary']))
                                <p class="text-sm text-gray-600 mt-1">{{ $job['summary'] }}</p>
                            @endif
                            @if (! empty($job['highlights']))
                                <ul class="list-disc list-inside text-sm text-gray-600 mt-1 space-y-0.5">
                                    @foreach ($job['highlights'] as $highlight)
                                        <li>{{ $highlight }}</li>
                                    @endforeach
                                </ul>
                            @endif
                            @if (! empty($job['crossReferencedProjects']))
                                <p class="text-xs text-gray-400 mt-1">Related projects: {{ implode(', ', $job['crossReferencedProjects']) }}</p>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- Projects --}}
        @if (! empty($data['projects']))
            <div>
                <h2 class="text-sm font-semibold text-gray-500 uppercase tracking-wide border-b border-gray-200 pb-1 mb-3">Projects</h2>
                <div class="space-y-4">
                    @foreach ($data['projects'] as $project)
                        <div>
                            <div class="flex justify-between items-baseline">
                                <div class="font-medium text-gray-900">{{ $project['name'] ?? '' }}</div>
                                <div class="text-xs text-gray-500 whitespace-nowrap ml-4">{{ $project['startDate'] ?? '' }} {{ $project['endDate'] ?? '' }}</div>
                            </div>
                            @if (! empty($project['description']))
                                <p class="text-sm text-gray-600 mt-1">{{ $project['description'] }}</p>
                            @endif
                            @if (! empty($project['keywords']))
                                <p class="text-xs text-gray-400 mt-1">{{ implode(' · ', $project['keywords']) }}</p>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- Education --}}
        @if (! empty($data['education']))
            <div>
                <h2 class="text-sm font-semibold text-gray-500 uppercase tracking-wide border-b border-gray-200 pb-1 mb-3">Education</h2>
                <div class="space-y-4">
                    @foreach ($data['education'] as $edu)
                        <div>
                            <div class="flex justify-between items-baseline">
                                <div class="font-medium text-gray-900">
                                    @if (! empty($edu['programs']))
                                        {{ collect($edu['programs'])->map(fn ($p) => trim(($p['designation'] ?? '').' '.($p['name'] ?? '').(! empty($p['concentration']) ? ' ('.$p['concentration'].')' : '')))->implode(', ') }}
                                    @else
                                        {{ $edu['area'] ?? '' }}
                                    @endif
                                </div>
                                <div class="text-xs text-gray-500 whitespace-nowrap ml-4">{{ $edu['startDate'] ?? '' }} — {{ $edu['endDate'] ?? '' }}</div>
                            </div>
                            <div class="text-sm text-gray-500">
                                {{ $edu['institution'] ?? '' }}@if (! empty($edu['subInstitution'])), {{ $edu['subInstitution'] }}@endif
                                @if (! empty($edu['gpa'])) — GPA {{ $edu['gpa'] }}@endif
                            </div>
                            @if (! empty($edu['courses']))
                                <p class="text-xs text-gray-400 mt-1">Courses: {{ implode(', ', $edu['courses']) }}</p>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- Remaining sections as compact lists --}}
        @foreach ([
            'certificates' => ['Certifications', fn ($c) => trim(($c['name'] ?? '').(! empty($c['issuer']) ? ' — '.$c['issuer'] : '').(! empty($c['date']) ? ' ('.$c['date'].')' : ''))],
            'volunteer' => ['Volunteer experience', fn ($v) => trim(($v['position'] ?? '').' — '.($v['organization'] ?? ''))],
            'publications' => ['Publications', fn ($p) => trim('“'.($p['name'] ?? '').'”'.(! empty($p['publisher']) ? ', '.$p['publisher'] : ''))],
            'awards' => ['Awards', fn ($a) => trim(($a['title'] ?? '').(! empty($a['awarder']) ? ' — '.$a['awarder'] : '').(! empty($a['date']) ? ' ('.$a['date'].')' : ''))],
            'languages' => ['Languages', fn ($l) => trim(($l['language'] ?? '').(! empty($l['fluency']) ? ' ('.$l['fluency'].')' : ''))],
            'interests' => ['Interests', fn ($i) => trim(($i['name'] ?? '').(! empty($i['keywords']) ? ': '.implode(', ', $i['keywords']) : ''))],
            'references' => ['References', fn ($r) => trim(($r['name'] ?? '').': '.($r['reference'] ?? ''))],
        ] as $key => [$label, $formatter])
            @if (! empty($data[$key]))
                <div>
                    <h2 class="text-sm font-semibold text-gray-500 uppercase tracking-wide border-b border-gray-200 pb-1 mb-3">{{ $label }}</h2>
                    <ul class="list-disc list-inside text-sm text-gray-600 space-y-0.5">
                        @foreach ($data[$key] as $item)
                            <li>{{ $formatter($item) }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif
        @endforeach
    </div>
@endsection
