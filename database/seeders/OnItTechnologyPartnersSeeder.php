<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Client;
use App\Models\User;
use Illuminate\Database\Seeder;

class OnItTechnologyPartnersSeeder extends Seeder
{
    /**
     * On IT internal SSO test user. Safe to re-run (updateOrCreate).
     */
    public function run(): void
    {
        $client = Client::where('slug', 'on-it-technology-partners')->first();

        if (! $client) {
            return;
        }

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
