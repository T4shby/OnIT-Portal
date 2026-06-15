<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Setting;
use App\Models\User;

class SettingPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role === UserRole::SuperAdmin;
    }

    public function update(User $user, ?Setting $setting = null): bool
    {
        return $user->role === UserRole::SuperAdmin;
    }
}
