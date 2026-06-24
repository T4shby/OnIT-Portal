<?php

namespace App\Support;

/**
 * Turns plain onboarding step strings into readable HTML (paths, labels, URLs).
 */
class OnboardingStepFormatter
{
    public static function rich(string $text): string
    {
        $html = e($text);

        $html = preg_replace(
            '/\*\*(.+?)\*\*/',
            '<strong class="onboarding-manual__emph">$1</strong>',
            $html,
        ) ?? $html;

        $html = preg_replace(
            '#(https?://[^\s<]+)#',
            '<a href="$1" target="_blank" rel="noopener" class="onboarding-manual__link">$1</a>',
            $html,
        ) ?? $html;

        $html = preg_replace(
            '/\b(On IT Portal - [^.<]+|SuperOps - [^.<]+)\b/u',
            '<strong class="onboarding-manual__name">$1</strong>',
            $html,
        ) ?? $html;

        if (str_contains($html, '→')) {
            $segments = preg_split('/\s*→\s*/', $html) ?: [];
            if (count($segments) > 1) {
                $wrapped = array_map(
                    static fn (string $segment): string => '<strong class="onboarding-manual__path">'.trim($segment).'</strong>',
                    $segments,
                );
                $html = implode('<span class="onboarding-manual__arrow" aria-hidden="true"> → </span>', $wrapped);
            }
        } elseif (preg_match('/^([A-Za-z][A-Za-z0-9 ()\/]+):(.+)$/', $text, $matches)) {
            $html = '<strong class="onboarding-manual__term">'.$matches[1].':</strong>'.e(ltrim($matches[2]));
        }

        return $html;
    }
}
