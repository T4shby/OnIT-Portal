<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Client;
use App\Models\User;
use Illuminate\Database\Seeder;

class OnItTechnologyPartnersSeeder extends Seeder
{
    /**
     * On IT's own organisation — used for internal SSO testing and demo.
     * Safe to re-run (updateOrCreate).
     */
    public function run(): void
    {
        $client = Client::updateOrCreate(
            ['slug' => 'on-it-technology-partners'],
            [
                'name' => 'On IT Technology Partners',
                'is_active' => true,
                'superops_sso_enabled' => true,
            ],
        );

        User::updateOrCreate(
            ['email' => 'portal.test@onit.ltd'],
            [
                'client_id' => $client->id,
                'name' => 'Portal Test',
                'role' => UserRole::ClientUser,
                'is_active' => true,
            ],
        );
    }
}
