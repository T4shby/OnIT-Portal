<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $email = config('services.portal.super_admin_email');

        if (! filled($email)) {
            return;
        }

        User::updateOrCreate(
            ['email' => $email],
            [
                'name' => 'On IT Admin',
                'role' => UserRole::SuperAdmin,
                'is_active' => true,
                'client_id' => null,
            ],
        );
    }
}
