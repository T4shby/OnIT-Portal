<?php

namespace App\Support;

class SuperOpsHtml
{
    /**
     * SuperOps ticket bodies are HTML. Plain newlines are collapsed in the PSA UI.
     * Also never emit Name <email> - SuperOps treats that as a tag.
     */
    public static function fromPlainText(string $text): string
    {
        $text = trim($text);

        if ($text === '') {
            return '';
        }

        if (str_starts_with($text, '<')) {
            return $text;
        }

        return nl2br(e($text), false);
    }

    public static function sanitize(string $html): string
    {
        $html = strip_tags($html, '<p><br><strong><b><em><i><ul><ol><li><div>');

        return preg_replace('/<(\/?)([a-z0-9]+)[^>]*>/i', '<$1$2>', $html) ?? $html;
    }
}
