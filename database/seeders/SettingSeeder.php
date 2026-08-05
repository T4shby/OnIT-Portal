<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

class SettingSeeder extends Seeder
{
    public function run(): void
    {
        $settings = [
            'portal_name' => 'On IT Portal',
            'support_email' => 'support@onit.ltd',
            'company_name' => 'On IT Technology Partners',
            // Adaptive auto-refresh (also editable on Integration Health)
            'freshness.hot_minutes' => '2.5',
            'freshness.work_idle_minutes' => '60',
            'freshness.off_hours_idle_minutes' => '60',
            'freshness.presence_minutes' => '15',
            'freshness.work_start' => '07:00',
            'freshness.work_end' => '19:00',
            'freshness.timezone' => 'Europe/London',
        ];

        foreach ($settings as $key => $value) {
            Setting::updateOrCreate(['key' => $key], ['value' => $value]);
        }
    }
}