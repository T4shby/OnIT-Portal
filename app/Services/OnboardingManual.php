<?php

namespace App\Services;

/**
 * Structured install-manual format for in-app onboarding steps.
 *
 * Prefer: short "already done automatically" + remaining actions.
 * Put long click-paths under recovery so technicians only open them when something failed.
 *
 * @phpstan-type ManualSection array{title: string, where: string|null, steps: list<string>, notes?: list<string>}
 * @phpstan-type ManualGuide array{
 *     prerequisites: list<string>,
 *     automated: list<string>,
 *     sections: list<ManualSection>,
 *     recovery: list<ManualSection>,
 *     verify: list<string>,
 *     notes: list<string>,
 *     warnings: list<string>,
 * }
 */
class OnboardingManual
{
    /**
     * @param  list<string>  $prerequisites
     * @param  list<string>  $automated  Already handled by Connect / portal jobs
     * @param  list<ManualSection>  $sections  What is left for the technician
     * @param  list<ManualSection>  $recovery  Only if remaining action fails
     * @param  list<string>  $verify
     * @param  list<string>  $notes
     * @param  list<string>  $warnings  Amber “do not ignore” platform warnings
     * @return ManualGuide
     */
    public static function build(
        array $prerequisites = [],
        array $sections = [],
        array $verify = [],
        array $notes = [],
        array $automated = [],
        array $recovery = [],
        array $warnings = [],
    ): array {
        return [
            'prerequisites' => $prerequisites,
            'automated' => $automated,
            'sections' => $sections,
            'recovery' => $recovery,
            'verify' => $verify,
            'notes' => $notes,
            'warnings' => $warnings,
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
        array $automated = [],
        array $recovery = [],
    ): array {
        return self::build(
            prerequisites: $prerequisites,
            sections: [self::section($sectionTitle, $where, $steps)],
            verify: $verify,
            notes: $notes,
            automated: $automated,
            recovery: $recovery,
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

        foreach ($guide['automated'] ?? [] as $line) {
            $lines[] = 'Already automatic: '.$line;
        }

        foreach ($guide['prerequisites'] as $line) {
            $lines[] = 'Start here: '.$line;
        }

        foreach ($guide['notes'] as $line) {
            $lines[] = 'Note: '.$line;
        }

        foreach ($guide['warnings'] ?? [] as $line) {
            $lines[] = 'Warning: '.$line;
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
                $lines[] = 'Note: '.$note;
            }
        }

        foreach ($guide['recovery'] ?? [] as $section) {
            $lines[] = 'If it fails: '.$section['title'];
            if ($section['where']) {
                $lines[] = $section['title'].' — Where: '.$section['where'];
            }
            foreach ($section['steps'] as $step) {
                $lines[] = $step;
            }
        }

        foreach ($guide['verify'] as $line) {
            $lines[] = 'Done when: '.$line;
        }

        return $lines;
    }
}
