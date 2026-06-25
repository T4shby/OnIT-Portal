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

    public static function hasSuperOpsSuffix(?string $displayName): bool
    {
        return (bool) preg_match('/\s+\((User Mailbox|User|Shared Mailbox)\)$/i', (string) $displayName);
    }

    /**
     * Entra SCIM expression for name.familyName — appends extensionAttribute1 to surname (or givenName if no surname).
     */
    public static function superOpsFamilyNameScimExpression(): string
    {
        return 'IIF(IsNullOrEmpty([extensionAttribute1]), [surname], IIF(IsNullOrEmpty([surname]), Join([givenName], " (", [extensionAttribute1], ")"), Join([surname], " (", [extensionAttribute1], ")")))';
    }
}
