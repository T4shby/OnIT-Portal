<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Client;
use App\Models\User;

class ClientPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role->isAdmin();
    }

    public function view(User $user, Client $client): bool
    {
        return $user->canAccessClient($client->id);
    }

    public function create(User $user): bool
    {
        return $user->role === UserRole::SuperAdmin;
    }

    public function update(User $user, Client $client): bool
    {
        return $user->role === UserRole::SuperAdmin
            || ($user->role === UserRole::AccountManager && $user->canAccessClient($client->id));
    }

    public function delete(User $user, Client $client): bool
    {
        return $user->role === UserRole::SuperAdmin;
    }
}
