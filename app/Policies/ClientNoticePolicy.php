<?php

namespace App\Policies;

use App\Models\ClientNotice;
use App\Models\User;

class ClientNoticePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, ClientNotice $notice): bool
    {
        return $user->canAccessClient($notice->client_id);
    }

    public function create(User $user): bool
    {
        return $user->role->isAdmin();
    }

    public function update(User $user, ClientNotice $notice): bool
    {
        return $user->role->isAdmin() && $user->canAccessClient($notice->client_id);
    }

    public function delete(User $user, ClientNotice $notice): bool
    {
        return $this->update($user, $notice);
    }
}
