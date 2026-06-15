<?php

namespace App\Policies;

use App\Models\ClientOpportunity;
use App\Models\User;

class ClientOpportunityPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, ClientOpportunity $opportunity): bool
    {
        return $user->canAccessClient($opportunity->client_id);
    }

    public function create(User $user): bool
    {
        return $user->role->isAdmin();
    }

    public function update(User $user, ClientOpportunity $opportunity): bool
    {
        return $user->role->isAdmin() && $user->canAccessClient($opportunity->client_id);
    }

    public function delete(User $user, ClientOpportunity $opportunity): bool
    {
        return $this->update($user, $opportunity);
    }
}
