<?php

namespace App\Enums;

enum UserProvisionSource: string
{
    case Manual = 'manual';
    case EntraSync = 'entra_sync';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Manual',
            self::EntraSync => 'Entra sync',
        };
    }
}
