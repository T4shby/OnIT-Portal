<?php

namespace Tests\Feature;

use App\Jobs\RefreshDropsuiteBackupJob;
use App\Jobs\RefreshHuntressSecurityJob;
use App\Jobs\RefreshM365DirectoryJob;
use App\Jobs\RefreshM365InsightsJob;
use App\Jobs\RefreshSuperOpsDashboardJob;
use App\Models\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PrewarmClientDashboardsTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_queues_configured_dashboard_refreshes_for_active_clients(): void
    {
        Bus::fake();

        config([
            'services.superops.api_token' => 'token',
            'services.superops.subdomain' => 'onitltd',
            'services.entra_sync.client_id' => 'client-id',
            'services.entra_sync.client_secret' => 'client-secret',
            'services.huntress.enabled' => true,
            'services.huntress.api_key' => 'key',
            'services.huntress.api_secret' => 'secret',
            'services.dropsuite.enabled' => true,
            'services.dropsuite.api_url' => 'https://dropsuite.example/api',
            'services.dropsuite.reseller_token' => 'reseller',
            'services.dropsuite.auth_token' => 'auth',
        ]);

        $client = Client::factory()->create([
            'is_active' => true,
            'superops_account_id' => 'superops-client',
            'entra_tenant_id' => '11111111-1111-1111-1111-111111111111',
            'huntress_organization_id' => 'huntress-client',
            'dropsuite_organization_id' => 'dropsuite-client',
        ]);

        $this->artisan('portal:prewarm-client-dashboards')->assertSuccessful();

        // SuperOps cold is always queued; warm+fresh SuperOps is not requeued.
        // M365/Huntress/Dropsuite only when cold or stale (client has no warm cache yet → queued).
        Bus::assertDispatched(RefreshSuperOpsDashboardJob::class, fn ($job) => $job->clientId === $client->id);
        Bus::assertDispatched(RefreshM365DirectoryJob::class, fn ($job) => $job->clientId === $client->id);
        Bus::assertDispatched(RefreshM365InsightsJob::class, fn ($job) => $job->clientId === $client->id);
        Bus::assertDispatched(RefreshHuntressSecurityJob::class, fn ($job) => $job->clientId === $client->id);
        Bus::assertDispatched(RefreshDropsuiteBackupJob::class, fn ($job) => $job->clientId === $client->id);
    }

    public function test_stale_superops_requeues_even_with_orphaned_in_progress_flag(): void
    {
        Bus::fake();

        config([
            'services.superops.api_token' => 'token',
            'services.superops.subdomain' => 'onitltd',
        ]);

        // Adaptive requeue must see age 30m as due (idle default is 60m).
        \App\Models\Setting::set('freshness.hot_minutes', '5');
        \App\Models\Setting::set('freshness.work_idle_minutes', '10');
        \App\Models\Setting::set('freshness.off_hours_idle_minutes', '10');
        Cache::forget('portal.freshness.snapshot.live');

        $client = Client::factory()->create([
            'is_active' => true,
            'superops_account_id' => 'superops-client',
        ]);

        Cache::put("client:{$client->id}:superops-dashboard:v2", [
            'assets_total' => 5,
            'open_tickets_total' => 1,
            'tickets_created' => ['7' => 1, '14' => 1, '30' => 1, 'all' => 1],
            'tickets_closed' => ['7' => 0, '14' => 0, '30' => 0, 'all' => 0],
            'last_refreshed_at' => now()->subMinutes(30)->toIso8601String(),
        ], now()->addDay());

        // Phantom flag with empty jobs table previously blocked prewarm forever.
        Cache::put('superops_dashboard.refresh_queued.'.$client->id, true, now()->addMinutes(15));

        $this->artisan('portal:prewarm-client-dashboards')->assertSuccessful();

        Bus::assertDispatched(RefreshSuperOpsDashboardJob::class, fn ($job) => $job->clientId === $client->id);
    }

    public function test_cold_superops_prewarm_even_when_jobs_queue_is_deep(): void
    {
        Bus::fake();

        config([
            'services.superops.api_token' => 'token',
            'services.superops.subdomain' => 'onitltd',
        ]);

        $client = Client::factory()->create([
            'is_active' => true,
            'superops_account_id' => 'superops-client',
            'entra_tenant_id' => '11111111-1111-1111-1111-111111111111',
            'huntress_organization_id' => 'huntress-client',
        ]);

        if (Schema::hasTable('jobs')) {
            for ($i = 0; $i < 45; $i++) {
                DB::table('jobs')->insert([
                    'queue' => 'default',
                    'payload' => '{}',
                    'attempts' => 0,
                    'reserved_at' => null,
                    'available_at' => time(),
                    'created_at' => time(),
                ]);
            }
        }

        $this->artisan('portal:prewarm-client-dashboards')->assertSuccessful();

        Bus::assertDispatched(RefreshSuperOpsDashboardJob::class, fn ($job) => $job->clientId === $client->id);
        Bus::assertNotDispatched(RefreshM365DirectoryJob::class);
        Bus::assertNotDispatched(RefreshHuntressSecurityJob::class);
    }

    public function test_does_not_requeue_superops_when_cache_already_warm(): void
    {
        Bus::fake();

        config([
            'services.superops.api_token' => 'token',
            'services.superops.subdomain' => 'onitltd',
            'services.superops.dashboard_cache_minutes' => 60,
        ]);

        $client = Client::factory()->create([
            'is_active' => true,
            'superops_account_id' => 'superops-client',
        ]);

        Cache::put("client:{$client->id}:superops-dashboard:v2", [
            'assets_total' => 5,
            'open_tickets_total' => 1,
            'tickets_created' => ['7' => 1, '14' => 1, '30' => 1, 'all' => 1],
            'tickets_closed' => ['7' => 0, '14' => 0, '30' => 0, 'all' => 0],
            'last_refreshed_at' => now()->toIso8601String(),
        ], now()->addDay());

        $this->artisan('portal:prewarm-client-dashboards')->assertSuccessful();

        Bus::assertNotDispatched(RefreshSuperOpsDashboardJob::class);
    }
}
