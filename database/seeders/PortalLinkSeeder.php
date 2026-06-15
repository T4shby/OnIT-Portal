<?php

namespace Database\Seeders;

use App\Services\ExternalServicesService;
use Illuminate\Database\Seeder;

class PortalLinkSeeder extends Seeder
{
    public function run(): void
    {
        app(ExternalServicesService::class)->syncDefaultLinks();
    }
}
