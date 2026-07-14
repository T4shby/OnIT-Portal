<?php

namespace App\Services;

/**
 * Structured install-manual format for in-app onboarding steps.
 *
 * @phpstan-type ManualSection array{title: string, where: string|null, steps: list<string>, notes?: list<string>}
 * @phpstan-type ManualGuide array{
 *     prerequisites: list<string>,
 *     sections: list<ManualSection>,
 *     verify: list<string>,
 *     notes: list<string>,
 * }
 */
class OnboardingManual
{
    /**
     * @param  list<string>  $prerequisites
     * @param  list<ManualSection>  $sections
     * @param  list<string>  $verify
     * @param  list<string>  $notes
     * @return ManualGuide
     */
    public static function build(
        array $prerequisites = [],
        array $sections = [],
        array $verify = [],
        array $notes = [],
    ): array {
        return [
            'prerequisites' => $prerequisites,
            'sections' => $sections,
            'verify' => $verify,
            'notes' => $notes,
        ];
    }

    /**
     * @param  list<string>  $steps
     * @return ManualSection
     */
    public static function section(string $title, ?string $where, array $steps, array $notes = []): array
    {
        $section = [
            'title' => $title,
            'where' => $where,
            'steps' => $steps,
        ];

        if ($notes !== []) {
            $section['notes'] = $notes;
        }

        return $section;
    }

    /**
     * Single block of numbered steps (no part title beyond the default).
     *
     * @param  list<string>  $steps
     * @return ManualGuide
     */
    public static function simple(
        ?string $where,
        array $steps,
        array $prerequisites = [],
        array $verify = [],
        array $notes = [],
        string $sectionTitle = 'Steps',
    ): array {
        return self::build(
            prerequisites: $prerequisites,
            sections: [self::section($sectionTitle, $where, $steps)],
            verify: $verify,
            notes: $notes,
        );
    }

    /**
     * Flatten guide text for search/tests (preserves legacy instructions shape).
     *
     * @param  ManualGuide  $guide
     * @return list<string>
     */
    public static function flatten(array $guide): array
    {
        $lines = [];

        foreach ($guide['prerequisites'] as $line) {
            $lines[] = 'Start here: '.$line;
        }

        foreach ($guide['notes'] as $line) {
            $lines[] = 'Remember: '.$line;
        }

        foreach ($guide['sections'] as $section) {
            if ($section['where']) {
                $lines[] = $section['title'].' — Where: '.$section['where'];
            } else {
                $lines[] = $section['title'];
            }

            foreach ($section['steps'] as $step) {
                $lines[] = $step;
            }

            foreach ($section['notes'] ?? [] as $note) {
                $lines[] = 'Remember: '.$note;
            }
        }

        foreach ($guide['verify'] as $line) {
            $lines[] = 'Done when: '.$line;
        }

        return $lines;
    }
}
