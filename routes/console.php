<?php

use App\Services\Admin\IntegrationHealthService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('portal:sync-entra-users')
    ->hourly()
    ->when(fn () => (bool) config('services.entra_sync.enabled'))
    ->withoutOverlapping(55);

// Single-server Plesk: do NOT use onOneServer() — stuck cache_locks rows block prewarm for hours.
Schedule::command('portal:prewarm-client-dashboards')
    ->everyFiveMinutes()
    ->withoutOverlapping(8);

// Proves minute cron + schedule:run are alive (Integration Health heartbeat).
Schedule::call(function () {
    Cache::put(
        IntegrationHealthService::SCHEDULER_TICK_KEY,
        now()->toIso8601String(),
        now()->addDay(),
    );
})->everyMinute()->name('portal-scheduler-tick');
