<?php

namespace App\Policies;

use App\Models\ClientRecommendation;
use App\Models\User;

class ClientRecommendationPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, ClientRecommendation $recommendation): bool
    {
        return $user->canAccessClient($recommendation->client_id);
    }

    public function create(User $user): bool
    {
        return $user->role->isAdmin();
    }

    public function update(User $user, ClientRecommendation $recommendation): bool
    {
        return $user->role->isAdmin() && $user->canAccessClient($recommendation->client_id);
    }

    public function delete(User $user, ClientRecommendation $recommendation): bool
    {
        return $this->update($user, $recommendation);
    }
}
