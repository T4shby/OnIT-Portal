<?php

namespace App\Enums;

enum UserProvisionSource: string
{
    case Manual = 'manual';
    case EntraSync = 'entra_sync';
}
