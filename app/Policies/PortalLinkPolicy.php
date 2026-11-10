<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\PortalLink;
use App\Models\User;

class PortalLinkPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role->isAdmin();
    }

    public function create(User $user): bool
    {
        return $user->role->isAdmin();
    }

    public function update(User $user, PortalLink $link): bool
    {
        if (! $user->role->isAdmin()) {
            return false;
        }

        // Global (all-tenant) links are Super Admin only - Account Managers must not
        // rewrite launch URLs that every customer dashboard includes.
        if ($link->client_id === null) {
            return $user->role === UserRole::SuperAdmin;
        }

        return $user->canAccessClient($link->client_id);
    }

    public function delete(User $user, PortalLink $link): bool
    {
        return $this->update($user, $link);
    }
}
