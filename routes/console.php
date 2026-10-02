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
 * Cron fires schedule:run every minute.
 * Product prewarm looks every minute and only queues a feed once that feed is
 * past its requeue age. Gating the whole sweep on the last-run heartbeat skips
 * a client that was still fresh at the last look, then leaves it past the
 * freshness target until the next full interval.
 * Entra user sync stays on the adaptive interval (hot 2.5m, idle hour).
 */
$intervalDue = static function (string $heartbeatKey): bool {
    return app(PortalFreshnessService::class)->isIntervalDue($heartbeatKey);
};

Schedule::command('portal:sync-entra-users')
    ->everyMinute()
    ->when(fn () => (bool) config('services.entra_sync.enabled')
        && $intervalDue(IntegrationHealthService::ENTRA_SCHEDULE_HEARTBEAT_KEY))
    ->withoutOverlapping(8);

// SuperOps + M365 + Huntress/Dropsuite. The command no-ops feeds that are still fresh.
// Single-server: do NOT use onOneServer() - stuck cache_locks can block for hours.
Schedule::command('portal:prewarm-client-dashboards')
    ->everyMinute()
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

// Drop activity_logs older than ACTIVITY_LOG_RETAIN_DAYS (default 90).
Schedule::command('model:prune', ['--model' => [\App\Models\ActivityLog::class]])
    ->dailyAt('03:20')
    ->withoutOverlapping(30);

// Database-backed tables that otherwise only grow: failed jobs older than 30 days,
// and cache rows that expired but were never read again (DatabaseStore only
// deletes an expired row when that key is read).
Schedule::command('queue:prune-failed', ['--hours' => 720])
    ->dailyAt('03:30')
    ->withoutOverlapping(30);

Schedule::command('portal:prune-expired-cache')
    ->dailyAt('03:40')
    ->withoutOverlapping(30);
