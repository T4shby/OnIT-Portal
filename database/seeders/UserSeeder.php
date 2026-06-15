<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Client;
use App\Models\User;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        User::create([
            'name' => 'On IT Admin',
            'email' => config('services.portal.super_admin_email'),
            'role' => UserRole::SuperAdmin,
            'is_active' => true,
        ]);

        $acme = Client::where('slug', 'acme-corporation')->first();
        $globex = Client::where('slug', 'globex-industries')->first();
        $initech = Client::where('slug', 'initech-solutions')->first();

        $accountManager = User::create([
            'name' => 'Sarah Account Manager',
            'email' => 'sarah.manager@onit.example',
            'role' => UserRole::AccountManager,
            'is_active' => true,
        ]);
        $accountManager->assignedClients()->sync([$acme->id, $globex->id]);

        foreach ([$acme, $globex, $initech] as $client) {
            User::create([
                'client_id' => $client->id,
                'name' => "Admin - {$client->name}",
                'email' => "admin@{$client->slug}.example",
                'role' => UserRole::ClientAdmin,
                'is_active' => true,
            ]);

            User::create([
                'client_id' => $client->id,
                'name' => "User - {$client->name}",
                'email' => "user@{$client->slug}.example",
                'role' => UserRole::ClientUser,
                'is_active' => true,
            ]);
        }
    }
}
