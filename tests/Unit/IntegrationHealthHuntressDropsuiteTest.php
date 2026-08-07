<?php

namespace Tests\Unit;

use App\Models\Client;
use App\Models\Setting;
use App\Services\Admin\IntegrationHealthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class IntegrationHealthHuntressDropsuiteTest extends TestCase
{
    use RefreshDatabase;

    public function test_tracks_huntress_feed_when_linked(): void
    {
        config([
            'services.huntress.enabled' => true,
            'services.huntress.api_key' => 'k',
            'services.huntress.api_secret' => 's',
        ]);

        Setting::set('freshness.hot_minutes', '10');
        Setting::set('freshness.work_idle_minutes', '10');
        Setting::set('freshness.off_hours_idle_minutes', '10');
        Cache::forget('portal.freshness.snapshot.live');

        $client = Client::factory()->create([
            'is_active' => true,
            'huntress_organization_id' => 'org-h',
        ]);

        Cache::put("client:{$client->id}:huntress-security:v1", [
            'agents_total' => 12,
            'open_incidents' => 0,
            'last_refreshed_at' => now()->subMinutes(10)->subSeconds(30)->toIso8601String(),
        ], now()->addDay());

        $overview = app(IntegrationHealthService::class)->overview();
        $cell = collect($overview['clients'][0]['integrations'])->firstWhere('key', 'huntress');

        $this->assertNotNull($cell);
        $this->assertSame('due', $cell['status']);
        $this->assertSame('Huntress security', $cell['friendly_label']);
    }

    public function test_dropsuite_disabled_when_not_linked(): void
    {
        config([
            'services.dropsuite.enabled' => true,
            'services.dropsuite.api_url' => 'https://dropsuite.us/api',
            'services.dropsuite.reseller_token' => 'r',
            'services.dropsuite.auth_token' => 'a',
        ]);

        $client = Client::factory()->create([
            'is_active' => true,
            'dropsuite_organization_id' => null,
        ]);

        $overview = app(IntegrationHealthService::class)->overview();
        $cell = collect($overview['clients'][0]['integrations'])->firstWhere('key', 'dropsuite');

        $this->assertSame('disabled', $cell['status']);
    }

    public function test_cold_sold_feed_raises_warning_not_all_ok(): void
    {
        config([
            'services.dropsuite.enabled' => true,
            'services.dropsuite.api_url' => 'https://dropsuite.us/api',
            'services.dropsuite.reseller_token' => 'r',
            'services.dropsuite.auth_token' => 'a',
        ]);

        // Scheduler/prewarm look healthy so cold is the only reason for attention.
        Cache::put(IntegrationHealthService::SCHEDULER_TICK_KEY, now()->toIso8601String(), now()->addHour());
        Cache::put(IntegrationHealthService::PREWARM_CACHE_KEY, [
            'at' => now()->toIso8601String(),
        ], now()->addHour());

        $client = Client::factory()->create([
            'is_active' => true,
            'dropsuite_organization_id' => '177210-12',
            'product_entitlements' => [
                'dropsuite' => ['entitled' => true],
            ],
        ]);

        $overview = app(IntegrationHealthService::class)->overview();
        $cell = collect($overview['clients'][0]['integrations'])->firstWhere('key', 'dropsuite');

        $this->assertSame('cold', $cell['status']);
        $this->assertSame('Never loaded', $cell['status_label']);
        $this->assertSame(1, $overview['cold_count']);
        $this->assertSame(1, $overview['clients'][0]['cold_count']);
        $this->assertSame('warning', $overview['pipeline']['severity_level']);
        $this->assertStringContainsString('never loaded', strtolower($overview['pipeline']['headline']));
        $this->assertTrue(collect($overview['notices'])->contains(
            fn (string $n): bool => str_contains(strtolower($n), 'never loaded')
                || str_contains(strtolower($n), 'never loaded a snapshot'),
        ));
        $this->assertFalse(collect($overview['notices'])->contains(
            fn (string $n): bool => str_contains($n, 'Nothing blocking'),
        ));
    }
}
