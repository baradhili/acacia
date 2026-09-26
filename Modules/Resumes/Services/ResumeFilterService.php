<?php

namespace Modules\Resumes\Services;

use Illuminate\Support\Str;

/**
 * Tailor a resume to a keyword list: keep the skills, jobs, projects,
 * education entries (and so on) that mention any (or all) of the
 * keywords, and strip the sections the filter emptied. A job is also
 * kept when it cross-references a project that survived the filter —
 * the resume reads coherently that way.
 *
 * Ported from baradhili/laravel-resume; the emptied-sections list now
 * carries 'certificates' (the section actually filtered) instead of
 * the repo's 'certifications' typo, which left empty sections behind.
 */
class ResumeFilterService
{
    public static function filter(
        array $parsedData,
        string|array $keywords,
        bool $matchAll = false
    ): array {
        $keywordList = self::normalizeKeywords($keywords);

        if (empty($keywordList)) {
            return $parsedData;
        }

        $filtered = $parsedData;
        // check projects first - if project matches filter then work item that crossrefs it is included
        $filteredProjects = self::filterProjects($parsedData['projects'] ?? [], $keywordList, $matchAll);
        $matchingProjectIds = self::extractProjectIdentifiers($filteredProjects);

        $filtered['skills'] = self::filterSkills($parsedData['skills'] ?? [], $keywordList, $matchAll);
        $filtered['work'] = self::filterWork($parsedData['work'] ?? [], $keywordList, $matchAll, $matchingProjectIds);
        $filtered['projects'] = $filteredProjects;
        $filtered['education'] = self::filterEducation($parsedData['education'] ?? [], $keywordList, $matchAll);
        $filtered['volunteer'] = self::filterVolunteer($parsedData['volunteer'] ?? [], $keywordList, $matchAll);
        $filtered['certificates'] = self::filterCertificates($parsedData['certificates'] ?? [], $keywordList, $matchAll);
        $filtered['publications'] = self::filterPublications($parsedData['publications'] ?? [], $keywordList, $matchAll);
        $filtered['awards'] = self::filterAwards($parsedData['awards'] ?? [], $keywordList, $matchAll);
        $filtered['languages'] = self::filterLanguages($parsedData['languages'] ?? [], $keywordList, $matchAll);
        $filtered['interests'] = self::filterInterests($parsedData['interests'] ?? [], $keywordList, $matchAll);
        $filtered['references'] = self::filterReferences($parsedData['references'] ?? [], $keywordList, $matchAll);

        // Update cross-referenced projects in work to only include filtered projects
        $filtered = self::syncCrossReferences($filtered);

        $filtered = self::removeEmptySections($filtered);

        return $filtered;
    }

    /**
     * Normalize keywords input to array of lowercase trimmed strings:
     * single string, comma-separated string, plain array or a
     * JSON-encoded array string.
     */
    protected static function normalizeKeywords(string|array $keywords): array
    {
        if (is_string($keywords) && str_starts_with(trim($keywords), '[')) {
            $decoded = json_decode($keywords, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $keywords = $decoded;
            }
        }

        if (is_string($keywords) && str_contains($keywords, ',')) {
            $keywords = array_map('trim', explode(',', $keywords));
        }

        if (is_string($keywords)) {
            $keywords = [$keywords];
        }

        return array_values(array_filter(array_map(
            fn ($k) => Str::of($k)->trim()->lower()->toString(),
            $keywords
        ), fn ($k) => ! empty($k)));
    }

    /**
     * Check if text matches any/all keywords.
     */
    protected static function matchesKeywords(string $text, array $keywords, bool $matchAll): bool
    {
        $textLower = Str::lower($text);

        foreach ($keywords as $keyword) {
            if (! Str::contains($textLower, $keyword)) {
                if ($matchAll) {
                    return false;
                }

                continue;
            }

            if (! $matchAll) {
                return true;
            }
        }

        return $matchAll;
    }

    /**
     * Extract identifiers (id and/or name) from filtered projects for cross-reference lookups.
     */
    protected static function extractProjectIdentifiers(array $projects): array
    {
        $identifiers = [];
        foreach ($projects as $project) {
            if (! empty($project['id'])) {
                $identifiers[$project['id']] = true;
            }
            if (! empty($project['name'])) {
                $identifiers[$project['name']] = true;
            }
        }

        return $identifiers;
    }

    protected static function filterSkills(array $skills, array $keywords, bool $matchAll): array
    {
        return array_values(array_filter($skills, function ($skill) use ($keywords, $matchAll) {
            if (! empty($skill['name']) && self::matchesKeywords($skill['name'], $keywords, $matchAll)) {
                return true;
            }
            if (! empty($skill['keywords']) && is_array($skill['keywords'])) {
                foreach ($skill['keywords'] as $kw) {
                    if (self::matchesKeywords($kw, $keywords, $matchAll)) {
                        return true;
                    }
                }
            }
            if (! empty($skill['level']) && self::matchesKeywords($skill['level'], $keywords, $matchAll)) {
                return true;
            }

            return false;
        }));
    }

    /**
     * Filter work experience. Includes items that reference any
     * project matching the keywords, even if the work item itself
     * doesn't match keywords directly.
     */
    protected static function filterWork(array $work, array $keywords, bool $matchAll, array $matchingProjectIds = []): array
    {
        return array_values(array_filter($work, function ($job) use ($keywords, $matchAll, $matchingProjectIds) {
            $fieldsToCheck = [
                $job['employer'] ?? '',
                $job['position'] ?? '',
                $job['summary'] ?? '',
                $job['description'] ?? '',
                $job['location'] ?? '',
                implode(' ', $job['highlights'] ?? []),
            ];

            foreach ($fieldsToCheck as $field) {
                if (! empty($field) && self::matchesKeywords($field, $keywords, $matchAll)) {
                    return true;
                }
            }

            if (! empty($job['keywords']) && is_array($job['keywords'])) {
                $keywordsText = implode(' ', array_filter($job['keywords'], 'is_string'));
                if (! empty($keywordsText) && self::matchesKeywords($keywordsText, $keywords, $matchAll)) {
                    return true;
                }
            }

            if (! empty($job['crossReferencedProjects']) && is_array($job['crossReferencedProjects'])) {
                foreach ($job['crossReferencedProjects'] as $ref) {
                    if (isset($matchingProjectIds[$ref])) {
                        return true;
                    }
                }
            }

            return false;
        }));
    }

    protected static function filterProjects(array $projects, array $keywords, bool $matchAll): array
    {
        return array_values(array_filter($projects, function ($project) use ($keywords, $matchAll) {
            $fieldsToCheck = [
                $project['name'] ?? '',
                $project['description'] ?? '',
                $project['summary'] ?? '',
                $project['entity'] ?? '',
                $project['type'] ?? '',
                implode(' ', array_filter($project['keywords'] ?? [], 'is_string')),
                implode(' ', $project['highlights'] ?? []),
            ];

            foreach ($fieldsToCheck as $field) {
                if (! empty($field) && self::matchesKeywords($field, $keywords, $matchAll)) {
                    return true;
                }
            }

            return false;
        }));
    }

    protected static function filterEducation(array $education, array $keywords, bool $matchAll): array
    {
        return array_values(array_filter($education, function ($edu) use ($keywords, $matchAll) {
            $fieldsToCheck = [
                $edu['institution'] ?? '',
                $edu['subInstitution'] ?? '',
                $edu['area'] ?? '',
                $edu['location'] ?? '',
                $edu['gpa'] ?? '',
                $edu['notes'] ?? '',
                implode(' ', array_map(
                    fn ($p) => implode(' ', [
                        $p['name'] ?? '',
                        $p['designation'] ?? '',
                        $p['concentration'] ?? '',
                        $p['type'] ?? '',
                    ]),
                    $edu['programs'] ?? []
                )),
                implode(' ', $edu['courses'] ?? []),
                implode(' ', $edu['awards'] ?? []),
                implode(' ', $edu['extracurriculars'] ?? []),
                implode(' ', $edu['keywords'] ?? []),
            ];

            foreach ($fieldsToCheck as $field) {
                if (! empty($field) && self::matchesKeywords($field, $keywords, $matchAll)) {
                    return true;
                }
            }

            return false;
        }));
    }

    protected static function filterVolunteer(array $items, array $keywords, bool $matchAll): array
    {
        return self::filterGenericItems($items, $keywords, $matchAll, ['organization', 'position', 'summary']);
    }

    protected static function filterCertificates(array $items, array $keywords, bool $matchAll): array
    {
        // certificates schema: 'name', 'issuer', 'summary', 'keywords'
        return self::filterGenericItems($items, $keywords, $matchAll, ['name', 'issuer', 'summary'], 'keywords');
    }

    protected static function filterPublications(array $items, array $keywords, bool $matchAll): array
    {
        return self::filterGenericItems($items, $keywords, $matchAll, ['name', 'publisher', 'summary']);
    }

    protected static function filterAwards(array $items, array $keywords, bool $matchAll): array
    {
        return self::filterGenericItems($items, $keywords, $matchAll, ['title', 'awarder', 'summary']);
    }

    protected static function filterLanguages(array $items, array $keywords, bool $matchAll): array
    {
        // languages schema: only 'language' and 'fluency' fields
        return self::filterGenericItems($items, $keywords, $matchAll, ['language', 'fluency']);
    }

    protected static function filterInterests(array $items, array $keywords, bool $matchAll): array
    {
        // interests schema: 'name' + 'keywords' (string[])
        return self::filterGenericItems($items, $keywords, $matchAll, ['name'], 'keywords');
    }

    protected static function filterReferences(array $items, array $keywords, bool $matchAll): array
    {
        // references schema: only 'name' and 'reference' fields
        return self::filterGenericItems($items, $keywords, $matchAll, ['name', 'reference']);
    }

    protected static function filterGenericItems(
        array $items,
        array $keywords,
        bool $matchAll,
        array $fields,
        ?string $keywordsField = null
    ): array {
        return array_values(array_filter($items, function ($item) use ($keywords, $matchAll, $fields, $keywordsField) {
            foreach ($fields as $field) {
                if (! empty($item[$field]) && self::matchesKeywords($item[$field], $keywords, $matchAll)) {
                    return true;
                }
            }
            if ($keywordsField && ! empty($item[$keywordsField]) && is_array($item[$keywordsField])) {
                foreach ($item[$keywordsField] as $kw) {
                    if (self::matchesKeywords($kw, $keywords, $matchAll)) {
                        return true;
                    }
                }
            }

            return false;
        }));
    }

    /**
     * Sync cross-referenced projects: remove references to filtered-out projects.
     */
    protected static function syncCrossReferences(array $filtered): array
    {
        $remainingProjectIds = [];
        foreach ($filtered['projects'] ?? [] as $project) {
            if (! empty($project['id'])) {
                $remainingProjectIds[$project['id']] = true;
            }
            if (! empty($project['name'])) {
                $remainingProjectIds[$project['name']] = true;
            }
        }

        if (! empty($filtered['work']) && is_array($filtered['work'])) {
            $filtered['work'] = array_map(function ($job) use ($remainingProjectIds) {
                if (! empty($job['crossReferencedProjects']) && is_array($job['crossReferencedProjects'])) {
                    $job['crossReferencedProjects'] = array_values(array_filter(
                        $job['crossReferencedProjects'],
                        fn ($ref) => isset($remainingProjectIds[$ref])
                    ));
                }

                return $job;
            }, $filtered['work']);
        }

        return $filtered;
    }

    /**
     * Remove sections that are empty after filtering.
     */
    protected static function removeEmptySections(array $data): array
    {
        $sections = [
            'skills',
            'work',
            'projects',
            'education',
            'volunteer',
            'certificates',
            'publications',
            'awards',
            'languages',
            'interests',
            'references',
        ];

        foreach ($sections as $section) {
            if (isset($data[$section]) && is_array($data[$section]) && empty($data[$section])) {
                unset($data[$section]);
            }
        }

        return $data;
    }

    /**
     * Per-section counts for the filter UI.
     */
    public static function getFilterMetadata(array $original, array $filtered): array
    {
        return [
            'original_counts' => [
                'skills' => count($original['skills'] ?? []),
                'work' => count($original['work'] ?? []),
                'projects' => count($original['projects'] ?? []),
                'education' => count($original['education'] ?? []),
            ],
            'filtered_counts' => [
                'skills' => count($filtered['skills'] ?? []),
                'work' => count($filtered['work'] ?? []),
                'projects' => count($filtered['projects'] ?? []),
                'education' => count($filtered['education'] ?? []),
            ],
            'removed_counts' => [
                'skills' => (count($original['skills'] ?? [])) - (count($filtered['skills'] ?? [])),
                'work' => (count($original['work'] ?? [])) - (count($filtered['work'] ?? [])),
                'projects' => (count($original['projects'] ?? [])) - (count($filtered['projects'] ?? [])),
                'education' => (count($original['education'] ?? [])) - (count($filtered['education'] ?? [])),
            ],
        ];
    }
}
