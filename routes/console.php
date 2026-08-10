<?php

use App\Services\Admin\IntegrationHealthService;
use App\Services\Portal\PortalFreshnessService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/**
 * Cron fires schedule:run every minute. Prewarm / Entra only run when the adaptive
 * cadence says the interval has elapsed (hot 2.5m with customers online, hour when idle).
 */
$intervalDue = static function (string $heartbeatKey): bool {
    return app(PortalFreshnessService::class)->isIntervalDue($heartbeatKey);
};

// Entra portal user sync — same adaptive cadence as prewarm.
Schedule::command('portal:sync-entra-users')
    ->everyMinute()
    ->when(fn () => (bool) config('services.entra_sync.enabled')
        && $intervalDue(IntegrationHealthService::ENTRA_SCHEDULE_HEARTBEAT_KEY))
    ->withoutOverlapping(8);

// SuperOps + M365 + Huntress/Dropsuite when due.
// Single-server: do NOT use onOneServer() — stuck cache_locks can block for hours.
Schedule::command('portal:prewarm-client-dashboards')
    ->everyMinute()
    ->when(fn () => $intervalDue(IntegrationHealthService::PREWARM_CACHE_KEY))
    ->withoutOverlapping(8);

// Proves minute cron + schedule:run are alive (Integration Health heartbeat).
Schedule::call(function () {
    Cache::put(
        IntegrationHealthService::SCHEDULER_TICK_KEY,
        now()->toIso8601String(),
        now()->addDay(),
    );
})->everyMinute()->name('portal-scheduler-tick');

// Nightly org metric snapshots for glance/report “last month” compare.
Schedule::command('portal:capture-metric-snapshots')
    ->dailyAt('02:15')
    ->withoutOverlapping(120);
