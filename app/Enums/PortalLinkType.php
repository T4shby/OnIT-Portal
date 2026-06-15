<?php

namespace App\Enums;

enum PortalLinkType: string
{
    case External = 'external';
    case SuperOpsEmbedded = 'superops_embedded';
    case SuperOpsSso = 'superops_sso';

    public function label(): string
    {
        return match ($this) {
            self::External => 'External URL',
            self::SuperOpsEmbedded => 'SuperOps Support (embedded)',
            self::SuperOpsSso => 'SuperOps Portal (SSO launch)',
        };
    }
}
