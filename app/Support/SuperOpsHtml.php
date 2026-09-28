<?php

namespace App\Support;

class SuperOpsHtml
{
    /**
     * SuperOps ticket bodies are HTML. Plain newlines are collapsed in the PSA UI.
     * Also never emit Name <email> - SuperOps treats that as a tag.
     *
     * Always escapes: this is only for untrusted/plain text. Callers that already built
     * trusted HTML (NewStarterTicketService) pass it through createTicket()'s
     * $descriptionIsHtml flag instead. (Previously any input starting with "<" was
     * returned raw, so a portal user could push arbitrary HTML into SuperOps, and a
     * description like "<jo@acme.com> printer broken" was eaten as a tag.)
     */
    public static function fromPlainText(string $text): string
    {
        $text = trim($text);

        if ($text === '') {
            return '';
        }

        return nl2br(e($text), false);
    }

    public static function sanitize(string $html): string
    {
        $html = strip_tags($html, '<p><br><strong><b><em><i><ul><ol><li><div>');

        return preg_replace('/<(\/?)([a-z0-9]+)[^>]*>/i', '<$1$2>', $html) ?? $html;
    }
}
