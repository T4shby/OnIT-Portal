<?php

namespace Tests\Unit;

use App\Models\Client;
use App\Services\Admin\IntegrationHealthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class IntegrationHealthServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_marks_long_running_directory_refresh_as_stuck(): void
    {
        $client = Client::factory()->create([
            'is_active' => true,
            'entra_tenant_id' => '11111111-1111-1111-1111-111111111111',
        ]);

        Cache::put('m365_directory.refresh_queued.'.$client->id, true, now()->addMinutes(30));
        Cache::put(
            'm365_directory.refresh_started.'.$client->id,
            now()->subMinutes(12)->toIso8601String(),
            now()->addMinutes(30),
        );

        $overview = app(IntegrationHealthService::class)->overview();

        $this->assertSame(1, $overview['stuck_count']);
        $this->assertTrue($overview['clients'][0]['is_stuck']);
        $this->assertSame('M365 directory', $overview['clients'][0]['active_process']);
    }

    public function test_clears_orphaned_queued_flag_when_jobs_table_empty(): void
    {
        $client = Client::factory()->create([
            'is_active' => true,
            'entra_sync_enabled' => true,
            'entra_tenant_id' => '11111111-1111-1111-1111-111111111111',
            'entra_synced_at' => now()->subHour(),
        ]);

        Cache::put('entra_sync.refresh_queued.'.$client->id, true, now()->addMinutes(30));

        $overview = app(IntegrationHealthService::class)->overview();
        $entra = collect($overview['clients'][0]['integrations'])->firstWhere('key', 'entra_sync');

        $this->assertSame('ok', $entra['status']);
        $this->assertFalse(Cache::has('entra_sync.refresh_queued.'.$client->id));
        $this->assertNull($overview['clients'][0]['active_process']);
        $this->assertSame(1, $overview['cleared_orphans']);
        $this->assertNotEmpty($overview['notices']);
        $this->assertArrayHasKey('pipeline', $overview);
        $this->assertArrayHasKey('prewarm', $overview['pipeline']);
        $this->assertArrayHasKey('scheduler', $overview['pipeline']);
        $this->assertArrayHasKey('headline', $overview['pipeline']);
    }

    public function test_marks_superops_due_when_past_requeue_before_client_window(): void
    {
        // Hot defaults: requeue ≈ 2.25m, soft window ≈ max(3.5, 2.875) → age 12m is past soft (aging).
        // Use a large idle-like interval so 12m is past requeue (~9m) but under soft (~13.8m).
        \App\Models\Setting::set('freshness.hot_minutes', '10');
        \App\Models\Setting::set('freshness.work_idle_minutes', '10');
        \App\Models\Setting::set('freshness.off_hours_idle_minutes', '10');
        \App\Models\Setting::set('freshness.presence_minutes', '15');
        Cache::forget('portal.freshness.snapshot.live');

        $client = Client::factory()->create([
            'is_active' => true,
            'superops_account_id' => 'acc-1',
        ]);

        Cache::put("client:{$client->id}:superops-dashboard:v4", [
            'assets_total' => 3,
            // Past requeue (~9m for 10m interval) but under soft window (~11.5m)
            'last_refreshed_at' => now()->subMinutes(10)->subSeconds(30)->toIso8601String(),
        ], now()->addDay());

        $overview = app(IntegrationHealthService::class)->overview();
        $superOps = collect($overview['clients'][0]['integrations'])->firstWhere('key', 'superops');

        $this->assertSame('due', $superOps['status']);
        $this->assertSame('Waiting to refresh', $superOps['status_label']);
        $this->assertTrue($superOps['due_for_requeue']);
        $this->assertSame(1, $overview['due_count']);
        $this->assertNotEmpty($superOps['blockers']);
        $this->assertFalse($superOps['flag_queued']);
        $this->assertFalse($superOps['job_in_db']);
    }
}
