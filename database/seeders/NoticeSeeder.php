<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\ClientNotice;
use Illuminate\Database\Seeder;

class NoticeSeeder extends Seeder
{
    public function run(): void
    {
        $notices = [
            'Welcome to the On IT Portal. Your single hub for all IT services.',
            'Scheduled maintenance window: Saturday 2am–6am. Minimal disruption expected.',
            'New security recommendations have been added to your dashboard.',
        ];

        Client::all()->each(function (Client $client) use ($notices) {
            foreach ($notices as $index => $body) {
                ClientNotice::create([
                    'client_id' => $client->id,
                    'title' => 'Notice '.($index + 1),
                    'body' => $body,
                    'published_at' => now()->subDays($index),
                    'expires_at' => $index === 1 ? now()->addDays(30) : null,
                    'is_active' => true,
                ]);
            }
        });
    }
}
