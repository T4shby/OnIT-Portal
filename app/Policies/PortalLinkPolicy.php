<?php

namespace App\Policies;

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

        return $link->client_id === null || $user->canAccessClient($link->client_id);
    }

    public function delete(User $user, PortalLink $link): bool
    {
        return $this->update($user, $link);
    }
}
