<?php

namespace App\Enums;

enum PortalLinkType: string
{
    case External = 'external';
    case SuperOpsEmbedded = 'superops_embedded';
    case SuperOpsSso = 'superops_sso';
    case Pax8Sso = 'pax8_sso';

    public function label(): string
    {
        return match ($this) {
            self::External => 'External URL',
            self::SuperOpsEmbedded => 'SuperOps Support (embedded)',
            self::SuperOpsSso => 'SuperOps Portal (SSO launch)',
            self::Pax8Sso => 'Pax8 Portal (SSO launch)',
        };
    }
}
