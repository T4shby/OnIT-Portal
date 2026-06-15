<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role->isAdmin();
    }

    public function view(User $actor, User $target): bool
    {
        if ($actor->role === UserRole::SuperAdmin) {
            return true;
        }

        if ($actor->role === UserRole::AccountManager) {
            return $target->client_id
                ? $actor->canAccessClient($target->client_id)
                : $actor->assignedClients()->where('users.id', $target->id)->exists();
        }

        return $actor->id === $target->id;
    }

    public function create(User $user): bool
    {
        return $user->role->isAdmin();
    }

    public function update(User $actor, User $target): bool
    {
        if ($actor->role === UserRole::SuperAdmin) {
            return true;
        }

        if ($actor->role === UserRole::AccountManager && $target->client_id) {
            return $actor->canAccessClient($target->client_id);
        }

        return $actor->id === $target->id;
    }

    public function delete(User $actor, User $target): bool
    {
        return $actor->role === UserRole::SuperAdmin && $actor->id !== $target->id;
    }
}
