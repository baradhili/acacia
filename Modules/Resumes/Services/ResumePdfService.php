<?php

namespace Modules\Resumes\Services;

use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Renders the LaTeX resume template to PDF. Ported from
 * baradhili/laravel-resume, with the techsemicolon/laravel-php-latex
 * fork replaced by a direct Symfony Process call to the configured
 * LuaLaTeX binary — same hardened environment (writable HOME and
 * TEXMFVAR, restricted PATH), one less dev-master dependency.
 *
 * The TEXMFVAR cache persists across runs so luaotfload's font
 * database is not rebuilt for every resume.
 */
class ResumePdfService
{
    /**
     * Render LaTeX source for the (already filtered) resume data.
     *
     * @param  array  $data  Filtered parsed_data
     * @param  bool  $isFiltered  Whether keywords were applied
     * @param  string  $keywordsString  Human-readable keyword list
     */
    public static function renderLatexSource(array $data, bool $isFiltered = false, string $keywordsString = ''): string
    {
        return view(config('resumes.latex.template'), self::prepareTemplateData($data, $isFiltered, $keywordsString))->render();
    }

    /**
     * Compile the (already filtered) resume data to PDF bytes.
     *
     * @throws RuntimeException when LaTeX is unavailable or compilation fails
     */
    public static function compile(array $data, bool $isFiltered = false, string $keywordsString = ''): string
    {
        if (! config('resumes.latex.enabled')) {
            throw new RuntimeException('PDF export is disabled on this server.');
        }

        $bin = config('resumes.latex.bin');
        if (! is_executable($bin)) {
            throw new RuntimeException("LaTeX binary not found at {$bin}.");
        }

        $dir = sys_get_temp_dir().'/resumes-'.bin2hex(random_bytes(8));
        if (! mkdir($dir, 0700, true)) {
            throw new RuntimeException('Could not create the LaTeX scratch directory.');
        }

        // Persistent font cache: luaotfload rebuilds its database on a miss
        $texmfVar = sys_get_temp_dir().'/resumes-texmf-var';
        if (! is_dir($texmfVar)) {
            @mkdir($texmfVar, 0700, true);
        }

        try {
            file_put_contents($dir.'/resume.tex', self::renderLatexSource($data, $isFiltered, $keywordsString));

            $process = new Process(
                [$bin, '--interaction=nonstopmode', '--halt-on-error', '--output-directory='.$dir, 'resume.tex'],
                $dir,
                [
                    'HOME' => $dir,
                    'PATH' => '/usr/local/bin:/usr/bin:/bin',
                    'TEXMFVAR' => $texmfVar,
                    'TEXINPUTS' => $dir.'://:',
                ],
                null,
                120
            );
            $process->run();

            if (! $process->isSuccessful() || ! is_file($dir.'/resume.pdf')) {
                Log::warning('Resume LaTeX compilation failed', [
                    'exit' => $process->getExitCode(),
                    'log' => mb_substr($process->getOutput(), -2000),
                ]);
                throw new RuntimeException('LaTeX compilation failed: '.mb_substr(trim($process->getOutput()), -500));
            }

            return file_get_contents($dir.'/resume.pdf');
        } finally {
            self::removeDirectory($dir);
        }
    }

    /**
     * Prepare data for the Blade LaTeX template with escaped values.
     */
    protected static function prepareTemplateData(array $parsedData, bool $isFiltered, string $keywordsString): array
    {
        $basics = $parsedData['basics'] ?? [];

        return [
            'name' => self::escapeLatex($basics['name'] ?? 'No Name'),
            'label' => self::escapeLatex($basics['label'] ?? ''),
            'email' => self::escapeLatex($basics['email'] ?? ''),
            'phone' => self::escapeLatex($basics['phone'] ?? ''),
            'location' => self::escapeLatex(self::formatLocation($basics['location'] ?? [])),
            'summary' => self::escapeLatex($basics['summary'] ?? ''),
            'links' => self::formatLinks($basics['links'] ?? []),
            'skills' => self::formatSkills($parsedData['skills'] ?? []),
            'work' => self::formatWork($parsedData['work'] ?? []),
            'projects' => self::formatProjects($parsedData['projects'] ?? []),
            'education' => self::formatEducation($parsedData['education'] ?? []),
            'volunteer' => self::formatGenericSection($parsedData['volunteer'] ?? [], ['position', 'organization', 'summary']),
            'certificates' => self::formatCertificates($parsedData['certificates'] ?? []),
            'publications' => self::formatGenericSection($parsedData['publications'] ?? [], ['name', 'publisher', 'releaseDate', 'url', 'summary']),
            'awards' => self::formatGenericSection($parsedData['awards'] ?? [], ['title', 'awarder', 'date', 'summary']),
            'languages' => self::formatGenericSection($parsedData['languages'] ?? [], ['language', 'fluency']),
            'interests' => self::formatInterests($parsedData['interests'] ?? []),
            'references' => self::formatGenericSection($parsedData['references'] ?? [], ['name', 'reference']),
            'generatedAt' => now()->format('F j, Y'),
            'isFiltered' => $isFiltered,
            'filterKeywords' => self::escapeLatex($keywordsString),
        ];
    }

    protected static function formatLocation(array $location): string
    {
        $parts = array_filter([
            $location['address'] ?? null,
            $location['city'] ?? null,
            $location['region'] ?? null,
            $location['postalCode'] ?? null,
            $location['countryCode'] ?? null,
        ]);

        return implode(', ', $parts);
    }

    protected static function formatLinks(array $links): array
    {
        return array_map(function ($link) {
            return [
                'url' => self::escapeLatex($link['url'] ?? ''),
                'label' => self::escapeLatex($link['label'] ?? ''),
            ];
        }, $links);
    }

    protected static function formatSkills(array $skills): array
    {
        return array_map(function ($skill) {
            return [
                'name' => self::escapeLatex($skill['name'] ?? ''),
                'level' => self::escapeLatex($skill['level'] ?? ''),
                'keywords' => array_map(fn ($k) => self::escapeLatex($k), $skill['keywords'] ?? []),
            ];
        }, $skills);
    }

    protected static function formatWork(array $work): array
    {
        return array_map(function ($job) {
            return [
                'position' => self::escapeLatex($job['position'] ?? ''),
                'employer' => self::escapeLatex($job['name'] ?? $job['employer'] ?? ''),
                'location' => self::escapeLatex($job['location'] ?? ''),
                'url' => self::escapeLatex($job['url'] ?? ''),
                'startDate' => $job['startDate'] ?? '',
                'endDate' => $job['endDate'] ?? '',
                'description' => self::escapeLatex($job['description'] ?? ''),
                'summary' => self::escapeLatex($job['summary'] ?? ''),
                'highlights' => array_map(fn ($h) => self::escapeLatex((string) $h), $job['highlights'] ?? []),
                'keywords' => array_map(fn ($k) => self::escapeLatex((string) $k), $job['keywords'] ?? []),
                'crossReferencedProjects' => $job['crossReferencedProjects'] ?? [],
            ];
        }, $work);
    }

    protected static function formatProjects(array $projects): array
    {
        return array_map(function ($project) {
            return [
                'id' => $project['id'] ?? '',
                'name' => self::escapeLatex($project['name'] ?? ''),
                'description' => self::escapeLatex($project['description'] ?? ''),
                'summary' => self::escapeLatex($project['summary'] ?? ''),
                'url' => self::escapeLatex($project['url'] ?? ''),
                'startDate' => $project['startDate'] ?? '',
                'endDate' => $project['endDate'] ?? '',
                'keywords' => array_map(fn ($k) => self::escapeLatex((string) $k), $project['keywords'] ?? []),
                'highlights' => array_map(fn ($h) => self::escapeLatex((string) $h), $project['highlights'] ?? []),
            ];
        }, $projects);
    }

    protected static function formatEducation(array $education): array
    {
        return array_map(function ($edu) {
            return [
                'institution' => self::escapeLatex($edu['institution'] ?? ''),
                'area' => self::escapeLatex($edu['area'] ?? ''),
                'location' => self::escapeLatex($edu['location'] ?? ''),
                'url' => self::escapeLatex($edu['url'] ?? ''),
                'subInstitution' => self::escapeLatex($edu['subInstitution'] ?? ''),
                'subInstitutionUrl' => self::escapeLatex($edu['subInstitutionUrl'] ?? ''),
                'startDate' => $edu['startDate'] ?? '',  // ISO8601 dates don't need escaping
                'endDate' => $edu['endDate'] ?? '',
                'gpa' => self::escapeLatex($edu['gpa'] ?? ''),
                'programs' => array_map(function ($prog) {
                    return [
                        'type' => self::escapeLatex($prog['type'] ?? ''),
                        'designation' => self::escapeLatex($prog['designation'] ?? ''),
                        'name' => self::escapeLatex($prog['name'] ?? ''),
                        'concentration' => self::escapeLatex($prog['concentration'] ?? ''),
                        'minor' => $prog['minor'] ?? false,
                        'gpa' => self::escapeLatex($prog['gpa'] ?? ''),
                        'honors' => self::escapeLatex($prog['honors'] ?? ''),
                    ];
                }, $edu['programs'] ?? []),
                'courses' => array_map(fn ($c) => self::escapeLatex((string) $c), $edu['courses'] ?? []),
                'awards' => array_map(fn ($a) => self::escapeLatex((string) $a), $edu['awards'] ?? []),
                'extracurriculars' => array_map(fn ($e) => self::escapeLatex((string) $e), $edu['extracurriculars'] ?? []),
                'keywords' => array_map(fn ($k) => self::escapeLatex((string) $k), $edu['keywords'] ?? []),
                'honorSocieties' => array_map(function ($society) {
                    return [
                        'name' => self::escapeLatex($society['name'] ?? ''),
                        'chapter' => self::escapeLatex($society['chapter'] ?? ''),
                        'memberId' => self::escapeLatex($society['memberId'] ?? ''),
                        'inductionDate' => $society['inductionDate'] ?? '',
                    ];
                }, $edu['honorSocieties'] ?? []),
                'notes' => self::escapeLatex($edu['notes'] ?? ''),
            ];
        }, $education);
    }

    protected static function formatCertificates(array $certificates): array
    {
        return array_map(function ($cert) {
            return [
                'name' => self::escapeLatex($cert['name'] ?? ''),
                'date' => $cert['date'] ?? '',
                'issuer' => self::escapeLatex($cert['issuer'] ?? ''),
                'url' => self::escapeLatex($cert['url'] ?? ''),
                'id' => self::escapeLatex($cert['id'] ?? ''),
                'keywords' => array_map(fn ($k) => self::escapeLatex((string) $k), $cert['keywords'] ?? []),
            ];
        }, $certificates);
    }

    protected static function formatInterests(array $interests): array
    {
        return array_map(function ($interest) {
            return [
                'name' => self::escapeLatex($interest['name'] ?? ''),
                'keywords' => array_map(fn ($k) => self::escapeLatex((string) $k), $interest['keywords'] ?? []),
            ];
        }, $interests);
    }

    protected static function formatGenericSection(array $items, array $fields): array
    {
        return array_map(function ($item) use ($fields) {
            $formatted = [];
            foreach ($fields as $field) {
                $value = $item[$field] ?? null;
                $formatted[$field] = is_array($value)
                    ? array_map(fn ($v) => self::escapeLatex((string) $v), $value)
                    : self::escapeLatex((string) ($value ?? ''));
            }

            return $formatted;
        }, $items);
    }

    /**
     * Escape special LaTeX characters to prevent compilation errors.
     */
    public static function escapeLatex(?string $text): string
    {
        if (empty($text)) {
            return '';
        }

        // Single-pass strtr: the braces \textbackslash{} itself emits
        // must not be re-escaped by the { } entries (str_replace
        // rescans its own output; the upstream repo gets that wrong).
        return strtr($text, [
            '\\' => '\\textbackslash{}',
            '{' => '\\{',
            '}' => '\\}',
            '&' => '\\&',
            '%' => '\\%',
            '$' => '\\$',
            '#' => '\\#',
            '_' => '\\_',
            '^' => '\\textasciicircum{}',
            '~' => '\\textasciitilde{}',
        ]);
    }

    protected static function removeDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) as $entry) {
            if (in_array($entry, ['.', '..'])) {
                continue;
            }
            $path = $dir.'/'.$entry;
            is_dir($path) ? self::removeDirectory($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
