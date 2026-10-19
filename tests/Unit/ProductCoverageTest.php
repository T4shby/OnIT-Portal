<?php

namespace Tests\Unit;

use App\Models\Client;
use App\Models\Setting;
use App\Services\Admin\IntegrationHealthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ProductCoverageTest extends TestCase
{
    use RefreshDatabase;

    public function test_coverage_counts_live_and_cold_sold_feeds(): void
    {
        config([
            'services.superops.api_token' => 'token',
            'services.superops.subdomain' => 'onitltd',
            'services.dropsuite.enabled' => true,
            'services.dropsuite.reseller_token' => 'r',
            'services.dropsuite.auth_token' => 'a',
        ]);

        Cache::put(IntegrationHealthService::SCHEDULER_TICK_KEY, now()->toIso8601String(), now()->addHour());
        Cache::put(IntegrationHealthService::PREWARM_CACHE_KEY, ['at' => now()->toIso8601String()], now()->addHour());
        Setting::set('freshness.hot_minutes', '10');
        Setting::set('freshness.work_idle_minutes', '10');
        Setting::set('freshness.off_hours_idle_minutes', '10');
        Cache::forget('portal.freshness.snapshot.live');

        $client = Client::factory()->create([
            'is_active' => true,
            'superops_account_id' => '111',
            'dropsuite_organization_id' => '6182',
            'product_entitlements' => [
                'superops' => ['entitled' => true],
                'dropsuite' => ['entitled' => true],
                'm365' => ['entitled' => false],
                'huntress' => ['entitled' => false],
            ],
        ]);

        Cache::put(app(\App\Services\SuperOps\SuperOpsClientMetricsService::class)->cacheKey($client->id), [
            'assets_total' => 1,
            'last_refreshed_at' => now()->subMinute()->toIso8601String(),
        ], now()->addHour());

        $coverage = app(IntegrationHealthService::class)->productCoverage();

        $this->assertGreaterThanOrEqual(1, $coverage['sold_feed_cells']);
        $this->assertGreaterThanOrEqual(1, $coverage['live_feed_cells']);
        // Dropsuite sold+mapped but no snapshot → cold
        $this->assertGreaterThanOrEqual(1, $coverage['cold_feed_cells']);
        $this->assertNotEmpty($coverage['rows']);
        $this->assertArrayHasKey('account_managers', $coverage['rows'][0]);
    }

    public function test_coverage_filter_and_csv_include_account_managers(): void
    {
        config([
            'services.superops.api_token' => 'token',
            'services.superops.subdomain' => 'onitltd',
        ]);

        Cache::put(IntegrationHealthService::SCHEDULER_TICK_KEY, now()->toIso8601String(), now()->addHour());
        Cache::put(IntegrationHealthService::PREWARM_CACHE_KEY, ['at' => now()->toIso8601String()], now()->addHour());

        $mine = Client::factory()->create([
            'name' => 'Covered Client',
            'is_active' => true,
            'superops_account_id' => '555',
            'product_entitlements' => [
                'superops' => ['entitled' => true],
                'dropsuite' => ['entitled' => false],
                'm365' => ['entitled' => false],
                'huntress' => ['entitled' => false],
            ],
        ]);
        $unassigned = Client::factory()->create([
            'name' => 'Open Book Client',
            'is_active' => true,
            'superops_account_id' => '666',
            'product_entitlements' => [
                'superops' => ['entitled' => true],
                'dropsuite' => ['entitled' => false],
                'm365' => ['entitled' => false],
                'huntress' => ['entitled' => false],
            ],
        ]);

        foreach ([$mine, $unassigned] as $client) {
            Cache::put(app(\App\Services\SuperOps\SuperOpsClientMetricsService::class)->cacheKey($client->id), [
                'assets_total' => 1,
                'last_refreshed_at' => now()->subMinute()->toIso8601String(),
            ], now()->addHour());
        }

        $manager = \App\Models\User::factory()->create([
            'role' => \App\Enums\UserRole::AccountManager,
            'name' => 'Alex Account',
            'is_active' => true,
        ]);
        $manager->assignedClients()->attach($mine);

        $service = app(IntegrationHealthService::class);
        $coverage = $service->productCoverage();
        $filtered = $service->filterCoverageByClientIds($coverage, [$mine->id]);
        $open = $service->filterCoverageWithoutAccountManager($coverage);
        $csv = $service->productCoverageCsv($filtered);

        $this->assertSame(1, $filtered['clients']);
        $this->assertSame('Alex Account', $filtered['rows'][0]['account_managers']);
        $this->assertSame('Open Book Client', $open['rows'][0]['client_name']);
        $this->assertStringContainsString('Account managers', $csv);
        $this->assertStringContainsString('Alex Account', $csv);
        $this->assertStringContainsString('Covered Client', $csv);
    }
}
