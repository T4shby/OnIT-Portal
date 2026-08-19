<?php

namespace App\Enums;

enum UserRole: string
{
    case SuperAdmin = 'super_admin';
    case AccountManager = 'account_manager';
    case ClientAdmin = 'client_admin';
    case ClientBillingAdmin = 'client_billing_admin';
    case ClientRequester = 'client_requester';

    /** @deprecated Use ClientRequester. Kept for legacy portal_links.required_role values. */
    case ClientUser = 'client_user';

    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Super Admin',
            self::AccountManager => 'Account Manager',
            self::ClientAdmin => 'Client Admin',
            self::ClientBillingAdmin => 'Client Billing Admin',
            self::ClientRequester => 'Client Requester',
            self::ClientUser => 'Client Requester',
        };
    }

    public function isAdmin(): bool
    {
        return in_array($this, [self::SuperAdmin, self::AccountManager], true);
    }

    public function isClientFacing(): bool
    {
        return in_array($this, [
            self::ClientAdmin,
            self::ClientBillingAdmin,
            self::ClientRequester,
            self::ClientUser,
        ], true);
    }

    public function isClientRequester(): bool
    {
        return in_array($this, [self::ClientRequester, self::ClientUser], true);
    }

    public function isClientBillingAdmin(): bool
    {
        return $this === self::ClientBillingAdmin;
    }

    public function isClientAdmin(): bool
    {
        return $this === self::ClientAdmin;
    }

    public function canAccessClientBilling(): bool
    {
        return in_array($this, [
            self::ClientBillingAdmin,
            self::ClientAdmin,
        ], true);
    }

    public function canViewClientAdminDashboard(): bool
    {
        // /services/support-devices for all client-facing roles.
        // Client Admin → org-wide SuperOps tickets and devices.
        // Requester / billing → personal tickets only.
        return $this->isClientFacing();
    }

    /**
     * Full org people lists, all tickets/cases/backups for the organisation.
     */
    public function canViewOrganisationWide(): bool
    {
        return $this === self::ClientAdmin;
    }

    public function canViewMicrosoft365Directory(): bool
    {
        return $this->isClientFacing();
    }

    public function canViewOrganisationTickets(): bool
    {
        return $this->isClientFacing();
    }

    public function canViewClientAssets(): bool
    {
        // Device fleet is organisation-wide admin context only.
        return $this === self::ClientAdmin;
    }

    public static function adminRoles(): array
    {
        return [self::SuperAdmin->value, self::AccountManager->value];
    }

    public static function clientRoles(): array
    {
        return [
            self::ClientRequester->value,
            self::ClientBillingAdmin->value,
            self::ClientAdmin->value,
        ];
    }

    public static function assignableClientRoles(): array
    {
        return [
            self::ClientRequester,
            self::ClientBillingAdmin,
            self::ClientAdmin,
        ];
    }
}
