<?php

namespace App\Services\EntraSync;

use App\Enums\EntraIdentityType;

class EntraSyncDisplayName
{
    public static function stripSuffix(?string $displayName): string
    {
        return trim((string) preg_replace('/\s+\((User Mailbox|User|Shared Mailbox)\)$/i', '', $displayName ?? ''));
    }

    public static function baseName(?string $displayName, ?string $emailFallback = null): string
    {
        $base = self::stripSuffix($displayName);

        if ($base !== '') {
            return $base;
        }

        return $emailFallback !== null ? (string) str($emailFallback)->before('@') : 'Unknown';
    }

    /** Combined SuperOps-style label. Directory table uses baseName() + a Type column instead. */
    public static function format(?string $displayName, EntraIdentityType $identityType, ?string $emailFallback = null): string
    {
        return self::baseName($displayName, $emailFallback).' ('.$identityType->displaySuffix().')';
    }

    /**
     * SuperOps SCIM last name - e.g. Smith (User Mailbox) or Accounts (Shared Mailbox).
     */
    public static function formatSuperOpsFamilyName(
        ?string $surname,
        ?string $givenName,
        ?string $displayName,
        EntraIdentityType $identityType,
        ?string $emailFallback = null,
    ): string {
        $suffix = $identityType->displaySuffix();
        $familyBase = trim((string) $surname);

        if ($familyBase === '') {
            $familyBase = trim((string) $givenName);
        }

        if ($familyBase === '') {
            $familyBase = self::baseName($displayName, $emailFallback);
        } else {
            $familyBase = self::stripSuffix($familyBase);
        }

        return $familyBase.' ('.$suffix.')';
    }

    /**
     * SuperOps first name (given). Never return a full email as first name.
     */
    public static function formatSuperOpsGivenName(
        ?string $givenName,
        ?string $displayName,
        ?string $emailFallback = null,
    ): string {
        $given = trim((string) $givenName);
        if ($given !== '' && ! str_contains($given, '@') && ! filter_var($given, FILTER_VALIDATE_EMAIL)) {
            return $given;
        }

        $base = self::baseName($displayName, $emailFallback);
        if ($base !== '' && ! str_contains($base, '@')) {
            // Prefer space-split first of display (Joe from "Joe Pearce")
            $parts = preg_split('/\s+/u', $base) ?: [];
            if (count($parts) >= 2 && isset($parts[0]) && $parts[0] !== '') {
                return $parts[0];
            }
            // Dot-local UPN style (richard.palmer) → first segment titled
            if (str_contains($base, '.')) {
                $segment = (string) str($base)->before('.');
                if ($segment !== '') {
                    return str($segment)->title()->toString();
                }
            }
            if (isset($parts[0]) && $parts[0] !== '' && ! str_contains($parts[0], '.')) {
                return $parts[0];
            }
        }

        if (is_string($emailFallback) && str_contains($emailFallback, '@')) {
            $local = (string) str($emailFallback)->before('@');
            $segment = str_contains($local, '.')
                ? (string) str($local)->before('.')
                : $local;
            if ($segment !== '') {
                return str($segment)->title()->toString();
            }
        }

        return 'User';
    }

    public static function hasSuperOpsSuffix(?string $displayName): bool
    {
        return (bool) preg_match('/\s+\((User Mailbox|User|Shared Mailbox)\)$/i', (string) $displayName);
    }
}
