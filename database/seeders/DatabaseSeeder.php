<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            ClientSeeder::class,
            UserSeeder::class,
            OnItTechnologyPartnersSeeder::class,
            PortalLinkSeeder::class,
            NoticeSeeder::class,
            RecommendationSeeder::class,
            OpportunitySeeder::class,
            SettingSeeder::class,
        ]);
    }
}
