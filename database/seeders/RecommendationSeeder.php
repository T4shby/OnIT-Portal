<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\ClientRecommendation;
use Illuminate\Database\Seeder;

class RecommendationSeeder extends Seeder
{
    public function run(): void
    {
        $recommendations = [
            ['title' => 'Enable MFA for all users', 'body' => 'Multi-factor authentication significantly reduces the risk of account compromise.', 'category' => 'security', 'priority' => 'high'],
            ['title' => 'Review backup retention policy', 'body' => 'Ensure your backup retention meets compliance requirements.', 'category' => 'service', 'priority' => 'medium'],
            ['title' => 'Upgrade to Windows 11', 'body' => 'Windows 10 end of support is approaching. Plan your device refresh.', 'category' => 'technology', 'priority' => 'medium'],
        ];

        Client::all()->each(function (Client $client) use ($recommendations) {
            foreach ($recommendations as $index => $rec) {
                ClientRecommendation::create([
                    'client_id' => $client->id,
                    'title' => $rec['title'],
                    'body' => $rec['body'],
                    'category' => $rec['category'],
                    'priority' => $rec['priority'],
                    'display_order' => $index + 1,
                    'is_active' => true,
                ]);
            }
        });
    }
}
