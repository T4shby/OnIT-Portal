<?php

namespace Database\Seeders;

use App\Models\Client;
use Illuminate\Database\Seeder;

class ClientSeeder extends Seeder
{
    public function run(): void
    {
        Client::updateOrCreate(
            ['slug' => 'on-it-technology-partners'],
            [
                'name' => 'On IT Technology Partners',
                'is_active' => true,
                'superops_sso_enabled' => true,
            ],
        );
    }
}
