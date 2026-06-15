<?php

namespace Database\Seeders;

use App\Models\Client;
use Illuminate\Database\Seeder;

class ClientSeeder extends Seeder
{
    public function run(): void
    {
        $clients = [
            [
                'name' => 'On IT Technology Partners',
                'slug' => 'on-it-technology-partners',
                'superops_sso_enabled' => true,
            ],
            ['name' => 'Acme Corporation', 'slug' => 'acme-corporation'],
            ['name' => 'Globex Industries', 'slug' => 'globex-industries'],
            ['name' => 'Initech Solutions', 'slug' => 'initech-solutions'],
        ];

        foreach ($clients as $client) {
            Client::updateOrCreate(
                ['slug' => $client['slug']],
                [
                    'name' => $client['name'],
                    'is_active' => true,
                    'superops_sso_enabled' => $client['superops_sso_enabled'] ?? false,
                ],
            );
        }
    }
}
