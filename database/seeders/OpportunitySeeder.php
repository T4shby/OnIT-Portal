<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\ClientOpportunity;
use Illuminate\Database\Seeder;

class OpportunitySeeder extends Seeder
{
    public function run(): void
    {
        $opportunities = [
            ['title' => 'Backup upgrade to cloud', 'body' => 'Enhance your backup strategy with cloud-based disaster recovery.', 'category' => 'backup', 'status' => 'open'],
            ['title' => 'Security awareness training', 'body' => 'Reduce phishing risk with employee security training.', 'category' => 'security', 'status' => 'open'],
            ['title' => 'Device refresh programme', 'body' => 'Replace ageing devices with modern, secure hardware.', 'category' => 'device_refresh', 'status' => 'open'],
        ];

        Client::all()->each(function (Client $client) use ($opportunities) {
            foreach ($opportunities as $index => $opp) {
                ClientOpportunity::create([
                    'client_id' => $client->id,
                    'title' => $opp['title'],
                    'body' => $opp['body'],
                    'category' => $opp['category'],
                    'status' => $opp['status'],
                    'display_order' => $index + 1,
                    'is_active' => true,
                ]);
            }
        });
    }
}
