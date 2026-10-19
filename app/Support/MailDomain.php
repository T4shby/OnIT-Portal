<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

class MailDomain
{
    /**
     * Normalise a customer mail domain for storage and DNS lookups.
     */
    public static function normalize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $domain = strtolower(trim($value));
        $domain = preg_replace('#^https?://#', '', $domain) ?? $domain;
        $domain = explode('/', $domain)[0] ?? $domain;
        $domain = ltrim($domain, '@');
        $domain = rtrim($domain, '.');

        if ($domain === '') {
            return null;
        }

        return $domain;
    }

    public static function isValid(?string $domain): bool
    {
        $domain = self::normalize($domain);
        if ($domain === null) {
            return false;
        }

        if (strlen($domain) > 253 || str_contains($domain, ' ') || str_contains($domain, '@')) {
            return false;
        }

        return (bool) preg_match(
            '/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/',
            $domain,
        );
    }

    /**
     * @throws ValidationException
     */
    public static function requireValid(?string $value, string $attribute = 'primary_domain'): string
    {
        $domain = self::normalize($value);
        if (! self::isValid($domain)) {
            throw ValidationException::withMessages([
                $attribute => 'Enter a valid main domain such as clientco.co.uk (no @ or https).',
            ]);
        }

        return $domain;
    }
}
