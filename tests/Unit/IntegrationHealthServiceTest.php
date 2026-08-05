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
    }

    public function test_marks_superops_due_when_past_requeue_before_client_window(): void
    {
        config([
            'services.superops.dashboard_refresh_after_minutes' => 10,
            'services.superops.dashboard_cache_minutes' => 15,
        ]);

        $client = Client::factory()->create([
            'is_active' => true,
            'superops_account_id' => 'acc-1',
        ]);

        Cache::put("client:{$client->id}:superops-dashboard:v2", [
            'assets_total' => 3,
            'last_refreshed_at' => now()->subMinutes(12)->toIso8601String(),
        ], now()->addDay());

        $overview = app(IntegrationHealthService::class)->overview();
        $superOps = collect($overview['clients'][0]['integrations'])->firstWhere('key', 'superops');

        $this->assertSame('due', $superOps['status']);
        $this->assertTrue($superOps['due_for_requeue']);
        $this->assertSame(1, $overview['due_count']);
        $this->assertNotEmpty($superOps['blockers']);
        $this->assertFalse($superOps['flag_queued']);
        $this->assertFalse($superOps['job_in_db']);
    }
}
