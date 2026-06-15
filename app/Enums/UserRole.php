<?php

namespace App\Enums;

enum UserRole: string
{
    case SuperAdmin = 'super_admin';
    case AccountManager = 'account_manager';
    case ClientAdmin = 'client_admin';
    case ClientUser = 'client_user';

    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Super Admin',
            self::AccountManager => 'Account Manager',
            self::ClientAdmin => 'Client Admin',
            self::ClientUser => 'Client User',
        };
    }

    public function isAdmin(): bool
    {
        return in_array($this, [self::SuperAdmin, self::AccountManager]);
    }

    public function isClientFacing(): bool
    {
        return in_array($this, [self::ClientAdmin, self::ClientUser]);
    }

    public static function adminRoles(): array
    {
        return [self::SuperAdmin->value, self::AccountManager->value];
    }

    public static function clientRoles(): array
    {
        return [self::ClientAdmin->value, self::ClientUser->value];
    }
}
