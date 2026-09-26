<?php

namespace Modules\Resumes\Services;

use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;

/**
 * Renders the (already filtered) resume data to a Word .docx via
 * PHPWord — the server has no pandoc, so this is the DOCX counterpart
 * of the LaTeX PDF pipeline: plain data in, document bytes out.
 */
class ResumeDocxService
{
    /**
     * @throws \RuntimeException when the document cannot be written
     */
    public static function render(array $data, bool $isFiltered = false, string $keywordsString = ''): string
    {
        $phpWord = new PhpWord;
        $phpWord->addNumberingStyle('resumes-bullet', [
            'type' => 'multilevel',
            'levels' => [[
                'format' => 'bullet',
                'text' => "\u{2022}",
                'alignment' => 'left',
                'tabPos' => 720,
            ]],
        ]);

        $section = $phpWord->addSection();
        $basics = $data['basics'] ?? [];

        // Header: name, label, contact line
        $section->addText($basics['name'] ?? 'No Name', ['bold' => true, 'size' => 20], ['alignment' => 'center']);
        if (! empty($basics['label'])) {
            $section->addText($basics['label'], ['italic' => true, 'size' => 10], ['alignment' => 'center']);
        }

        $contact = array_filter([
            $basics['email'] ?? null,
            $basics['phone'] ?? null,
            self::formatLocation($basics['location'] ?? []),
        ]);
        foreach ($basics['links'] ?? [] as $link) {
            $contact[] = $link['label'] ?? $link['url'] ?? null;
        }
        if ($contact) {
            $section->addText(implode(' | ', $contact), ['size' => 9, 'color' => '666666'], ['alignment' => 'center']);
        }

        if (! empty($basics['summary'])) {
            self::heading($section, 'Summary');
            $section->addText($basics['summary']);
        }

        if (! empty($data['skills'])) {
            self::heading($section, 'Skills');
            foreach ($data['skills'] as $skill) {
                $run = [];
                $run[] = ['text' => $skill['name'] ?? '', 'bold' => true];
                if (! empty($skill['level'])) {
                    $run[] = ['text' => ' ('.$skill['level'].')', 'italic' => true];
                }
                if (! empty($skill['keywords']) && is_array($skill['keywords'])) {
                    $run[] = ['text' => ': '.implode(', ', $skill['keywords'])];
                }
                self::textRuns($section, $run);
            }
        }

        if (! empty($data['work'])) {
            self::heading($section, 'Work Experience');
            foreach ($data['work'] as $job) {
                self::entryHeading($section,
                    $job['position'] ?? '',
                    $job['name'] ?? $job['employer'] ?? '',
                    $job['location'] ?? '',
                    $job['startDate'] ?? '',
                    $job['endDate'] ?? ''
                );
                self::paragraphs($section, $job['summary'] ?? null);
                self::paragraphs($section, $job['description'] ?? null);
                self::bullets($section, $job['highlights'] ?? []);
                if (! empty($job['crossReferencedProjects']) && is_array($job['crossReferencedProjects'])) {
                    self::note($section, 'Related projects: '.implode(', ', $job['crossReferencedProjects']));
                }
            }
        }

        if (! empty($data['projects'])) {
            self::heading($section, 'Projects');
            foreach ($data['projects'] as $project) {
                self::entryHeading($section,
                    $project['name'] ?? '',
                    $project['entity'] ?? $project['type'] ?? '',
                    '',
                    $project['startDate'] ?? '',
                    $project['endDate'] ?? ''
                );
                self::paragraphs($section, $project['description'] ?? $project['summary'] ?? null);
                self::bullets($section, $project['highlights'] ?? []);
            }
        }

        if (! empty($data['education'])) {
            self::heading($section, 'Education');
            foreach ($data['education'] as $edu) {
                $title = $edu['area'] ?? '';
                if (! empty($edu['programs']) && is_array($edu['programs'])) {
                    $title = implode(', ', array_map(fn ($p) => trim(($p['designation'] ?? '').' '.($p['name'] ?? '').(! empty($p['concentration']) ? ' ('.$p['concentration'].')' : '')), $edu['programs']));
                }
                self::entryHeading($section, $title, $edu['institution'] ?? '', $edu['location'] ?? '', $edu['startDate'] ?? '', $edu['endDate'] ?? '');
                if (! empty($edu['gpa'])) {
                    self::note($section, 'GPA: '.$edu['gpa']);
                }
                if (! empty($edu['courses']) && is_array($edu['courses'])) {
                    self::note($section, 'Relevant courses: '.implode(', ', $edu['courses']));
                }
                self::paragraphs($section, $edu['notes'] ?? null);
            }
        }

        foreach ([['volunteer', 'Volunteer Experience'], ['certificates', 'Certifications'], ['publications', 'Publications'], ['awards', 'Awards'], ['languages', 'Languages'], ['interests', 'Interests'], ['references', 'References']] as [$key, $label]) {
            if (empty($data[$key])) {
                continue;
            }
            self::heading($section, $label);
            foreach ($data[$key] as $item) {
                self::paragraphs($section, self::genericLine($key, $item));
            }
        }

        if ($isFiltered) {
            $section->addText('');
            self::note($section, 'This resume was filtered by keywords: '.$keywordsString);
        }

        $tempFile = tempnam(sys_get_temp_dir(), 'resumes-docx-');
        try {
            IOFactory::createWriter($phpWord, 'Word2007')->save($tempFile);

            $contents = file_get_contents($tempFile);
            if ($contents === false || strlen($contents) < 4 || substr($contents, 0, 2) !== 'PK') {
                throw new \RuntimeException('The DOCX writer produced an empty document.');
            }

            return $contents;
        } finally {
            @unlink($tempFile);
        }
    }

    protected static function heading($section, string $text): void
    {
        $section->addText($text, ['bold' => true, 'size' => 13], ['spacingBefore' => 240, 'spacingAfter' => 60, 'borderBottom' => ['size' => 6, 'color' => '999999']]);
    }

    /**
     * "Position — Employer, Location   Start – End" entry heading.
     */
    protected static function entryHeading($section, string $title, string $subtitle, string $location, string $start, string $end): void
    {
        $left = trim($title);
        if ($subtitle !== '') {
            $left .= $left !== '' ? ' — ' : '';
            $left .= $subtitle;
        }
        if ($location !== '') {
            $left .= ', '.$location;
        }
        $dates = trim(($start !== '' ? $start : '').' – '.($end !== '' ? $end : 'Present'), ' –');

        $section->addText(
            $left !== '' ? $left : ' ',
            ['bold' => true],
            ['keepNext' => true, 'tabs' => [['type' => 'right', 'position' => 9360]]]
        );
        if ($dates !== '' && $dates !== 'Present') {
            $section->addText($dates, ['size' => 9, 'color' => '666666']);
        }
    }

    protected static function textRuns($section, array $runs): void
    {
        $textRun = $section->addTextRun();
        foreach ($runs as $run) {
            $textRun->addText($run['text'], ['bold' => $run['bold'] ?? false, 'italic' => $run['italic'] ?? false]);
        }
    }

    protected static function paragraphs($section, ?string $text): void
    {
        if (! empty($text)) {
            $section->addText($text);
        }
    }

    protected static function bullets($section, array $items): void
    {
        foreach ($items as $item) {
            if (is_string($item) && $item !== '') {
                $section->addListItem($item, 0, null, 'resumes-bullet');
            }
        }
    }

    protected static function note($section, string $text): void
    {
        $section->addText($text, ['size' => 9, 'color' => '666666']);
    }

    protected static function genericLine(string $key, array $item): string
    {
        return match ($key) {
            'volunteer' => trim((($item['position'] ?? '') !== '' ? $item['position'].' — ' : '').($item['organization'] ?? '').(! empty($item['summary']) ? ': '.$item['summary'] : '')),
            'certificates' => trim((($item['name'] ?? '') !== '' ? $item['name'] : '').(! empty($item['issuer']) ? ' ('.$item['issuer'].')' : '').(! empty($item['date']) ? ' — '.$item['date'] : '')),
            'publications' => trim((($item['name'] ?? '') !== '' ? '"'.$item['name'].'"' : '').(! empty($item['publisher']) ? ', '.$item['publisher'] : '').(! empty($item['releaseDate']) ? ' ('.$item['releaseDate'].')' : '')),
            'awards' => trim((($item['title'] ?? '') !== '' ? $item['title'] : '').(! empty($item['awarder']) ? ' — '.$item['awarder'] : '').(! empty($item['date']) ? ' ('.$item['date'].')' : '')),
            'languages' => trim((($item['language'] ?? '') !== '' ? $item['language'] : '').(! empty($item['fluency']) ? ' ('.$item['fluency'].')' : '')),
            'interests' => trim((($item['name'] ?? '') !== '' ? $item['name'] : '').(! empty($item['keywords']) && is_array($item['keywords']) ? ': '.implode(', ', $item['keywords']) : '')),
            'references' => trim((($item['name'] ?? '') !== '' ? $item['name'] : '').(! empty($item['reference']) ? ': '.$item['reference'] : '')),
            default => '',
        };
    }

    protected static function formatLocation(array $location): string
    {
        $parts = array_filter([
            $location['city'] ?? null,
            $location['region'] ?? null,
            $location['countryCode'] ?? null,
        ]);

        return implode(', ', $parts);
    }
}
