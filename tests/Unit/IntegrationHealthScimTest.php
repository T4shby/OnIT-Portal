<?php

namespace Tests\Unit;

use App\Models\Client;
use App\Services\Admin\IntegrationHealthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class IntegrationHealthScimTest extends TestCase
{
    use RefreshDatabase;

    public function test_superops_scim_export_stopped_surfaces_as_failed_on_integration_health(): void
    {
        $client = Client::factory()->create([
            'is_active' => true,
            'entra_sync_enabled' => true,
            'entra_tenant_id' => '11111111-1111-1111-1111-111111111111',
            'entra_superops_app_id' => '22222222-2222-2222-2222-222222222222',
            'superops_account_id' => 'acc-yor',
        ]);

        Cache::put('scim.health.'.$client->id, [
            'ok' => false,
            'error' => null,
            'needsApplyScim' => false,
            'needsRepair' => true,
            'jobState' => '',
            'warnings' => ['No SCIM provisioning job in Entra'],
        ], now()->addMinutes(5));

        $metrics = app(\App\Services\SuperOps\SuperOpsClientMetricsService::class);
        Cache::put($metrics->cacheKey($client->id), [
            'assets_total' => 1,
            'last_refreshed_at' => now()->subMinute()->toIso8601String(),
        ], now()->addDay());

        $overview = app(IntegrationHealthService::class)->overview();
        $row = collect($overview['clients'])->firstWhere('client_id', $client->id);
        $superOps = collect($row['integrations'])->firstWhere('key', 'superops');
        $scim = collect($row['integrations'])->firstWhere('key', 'superops_scim');

        $this->assertContains($superOps['status'], ['ok', 'due']);
        $this->assertSame('failed', $scim['status']);
        $this->assertSame('Export stopped', $scim['status_label']);
        $this->assertSame(1, $row['failed_count']);
        $this->assertNotEmpty($scim['blockers']);
    }

    public function test_superops_scim_setup_needed_when_apply_scim_incomplete(): void
    {
        $client = Client::factory()->create([
            'is_active' => true,
            'entra_sync_enabled' => true,
            'entra_tenant_id' => '11111111-1111-1111-1111-111111111111',
            'entra_superops_app_id' => '22222222-2222-2222-2222-222222222222',
            'superops_account_id' => 'acc-mxvi',
        ]);

        Cache::put('scim.health.'.$client->id, [
            'ok' => false,
            'error' => null,
            'needsApplyScim' => true,
            'needsRepair' => true,
            'warnings' => ['SuperOps SCIM Tenant URL is not stored in Entra'],
        ], now()->addMinutes(5));

        $scim = collect(app(IntegrationHealthService::class)->overview()['clients'][0]['integrations'])
            ->firstWhere('key', 'superops_scim');

        $this->assertSame('failed', $scim['status']);
        $this->assertSame('Setup needed', $scim['status_label']);
    }

    public function test_superops_scim_column_omitted_when_entra_sync_off(): void
    {
        $client = Client::factory()->create([
            'is_active' => true,
            'entra_sync_enabled' => false,
            'entra_tenant_id' => '11111111-1111-1111-1111-111111111111',
            'superops_account_id' => 'acc-1',
        ]);

        $keys = collect(app(IntegrationHealthService::class)->overview()['clients'][0]['integrations'])
            ->pluck('key')
            ->all();

        $this->assertNotContains('superops_scim', $keys);
    }
}
