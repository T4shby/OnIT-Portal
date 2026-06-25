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

    /** Portal / M365 directory label — does not write to Entra displayName. */
    public static function format(?string $displayName, EntraIdentityType $identityType, ?string $emailFallback = null): string
    {
        return self::baseName($displayName, $emailFallback).' ('.$identityType->displaySuffix().')';
    }

    /**
     * SuperOps SCIM last name — e.g. Munns (User Mailbox) or Accounts (Shared Mailbox).
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

    public static function hasSuperOpsSuffix(?string $displayName): bool
    {
        return (bool) preg_match('/\s+\((User Mailbox|User|Shared Mailbox)\)$/i', (string) $displayName);
    }
}
