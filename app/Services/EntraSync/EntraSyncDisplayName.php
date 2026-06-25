<?php

namespace App\Services\EntraSync;

use App\Enums\EntraIdentityType;

class EntraSyncDisplayName
{
    public static function format(?string $displayName, EntraIdentityType $identityType, ?string $emailFallback = null): string
    {
        $base = trim((string) preg_replace('/\s+\((User Mailbox|User|Shared Mailbox)\)$/i', '', $displayName ?? ''));

        if ($base === '') {
            $base = $emailFallback !== null ? (string) str($emailFallback)->before('@') : 'Unknown';
        }

        return $base.' ('.$identityType->displaySuffix().')';
    }
}
