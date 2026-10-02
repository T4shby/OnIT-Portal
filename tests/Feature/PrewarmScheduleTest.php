<?php

namespace Tests\Feature;

use App\Services\Admin\IntegrationHealthService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class PrewarmScheduleTest extends TestCase
{
    use RefreshDatabase;

    public function test_prewarm_still_runs_when_the_last_sweep_was_recent(): void
    {
        Cache::put(IntegrationHealthService::PREWARM_CACHE_KEY, [
            'at' => now()->toIso8601String(),
            'superops_queued' => 0,
        ], now()->addDay());

        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event) => str_contains((string) $event->command, 'portal:prewarm-client-dashboards'));

        $this->assertNotNull($event);
        $this->assertTrue($event->filtersPass($this->app));
    }

    public function test_entra_sync_stays_on_the_adaptive_interval(): void
    {
        config(['services.entra_sync.enabled' => true]);

        Cache::put(
            IntegrationHealthService::ENTRA_SCHEDULE_HEARTBEAT_KEY,
            now()->toIso8601String(),
            now()->addDay(),
        );

        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event) => str_contains((string) $event->command, 'portal:sync-entra-users'));

        $this->assertNotNull($event);
        $this->assertFalse($event->filtersPass($this->app));

        Cache::forget(IntegrationHealthService::ENTRA_SCHEDULE_HEARTBEAT_KEY);

        $this->assertTrue($event->filtersPass($this->app));
    }
}
