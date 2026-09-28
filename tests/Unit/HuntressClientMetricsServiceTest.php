<?php

namespace Tests\Unit;

use App\Models\Client;
use App\Services\Huntress\HuntressClientMetricsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HuntressClientMetricsServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.huntress.api_key' => 'huntress-test-key',
            'services.huntress.api_secret' => 'huntress-test-secret',
            'services.huntress.enabled' => true,
        ]);
    }

    public function test_refresh_maps_organization_edr_stats(): void
    {
        Http::fake([
            'https://api.huntress.io/v1/organizations/org-42' => Http::response([
                'organization' => [
                    'id' => 'org-42',
                    'name' => 'Example Org',
                    'open_incident_reports_count' => 3,
                    'edr' => [
                        'agents_count' => 48,
                        'unresponsive_agents_count' => 2,
                        'isolated_agents_count' => 1,
                    ],
                ],
            ], 200),
            'https://api.huntress.io/v1/incident_reports*' => Http::response([
                'incident_reports' => [
                    [
                        'id' => 10,
                        'organization_id' => 'org-42',
                        'subject' => 'Open case',
                        'status' => 'open',
                        'severity' => 'high',
                        'summary' => 'Something bad',
                        'sent_at' => now()->toIso8601String(),
                    ],
                    [
                        'id' => 11,
                        'organization_id' => 'org-42',
                        'subject' => 'Closed case',
                        'status' => 'closed',
                        'severity' => 'low',
                        'summary' => 'Fixed',
                        'closed_at' => now()->toIso8601String(),
                    ],
                    [
                        'id' => 12,
                        'organization_id' => 'org-42',
                        'subject' => 'Also open',
                        'status' => 'open',
                        'severity' => 'medium',
                        'summary' => 'Still open',
                        'sent_at' => now()->toIso8601String(),
                    ],
                ],
                'pagination' => [],
            ], 200),
        ]);

        $client = Client::factory()->create(['huntress_organization_id' => 'org-42']);
        $summary = app(HuntressClientMetricsService::class)->refreshAndStore($client);

        $this->assertTrue($summary->available);
        $this->assertNull($summary->unavailableReason);
        $this->assertSame(48, $summary->agentsTotal);
        $this->assertSame(2, $summary->agentsUnresponsive);
        $this->assertSame(2, $summary->openIncidents);
        $this->assertSame(1, $summary->resolvedIncidents);
        $this->assertSame(1, $summary->edrIsolatedAgents);
        $this->assertFalse($summary->isStale);
        $this->assertNotNull($summary->lastRefreshedAt);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.huntress.io/v1/organizations/org-42'
                && $request->hasHeader('Authorization');
        });
    }

    public function test_unavailable_when_organization_id_missing(): void
    {
        $client = Client::factory()->create([
            'huntress_organization_id' => null,
            'product_entitlements' => ['huntress' => ['entitled' => true]],
        ]);
        $summary = app(HuntressClientMetricsService::class)->summaryForClient($client);

        $this->assertFalse($summary->available);
        $this->assertSame('Huntress is not connected for this organisation.', $summary->unavailableReason);
        Http::assertNothingSent();
    }

    public function test_unavailable_when_api_not_configured(): void
    {
        config(['services.huntress.enabled' => false]);

        $client = Client::factory()->create(['huntress_organization_id' => 'org-42']);
        $summary = app(HuntressClientMetricsService::class)->summaryForClient($client);

        $this->assertFalse($summary->available);
        $this->assertSame('Huntress API is not configured.', $summary->unavailableReason);
        Http::assertNothingSent();
    }

    public function test_cached_summary_is_isolated_per_client(): void
    {
        $clientA = Client::factory()->create(['huntress_organization_id' => 'org-a']);
        $clientB = Client::factory()->create(['huntress_organization_id' => 'org-b']);

        Cache::put("client:{$clientA->id}:huntress-security:v1", [
            'agents_total' => 10,
            'agents_unresponsive' => 1,
            'open_incidents' => 0,
            'edr_isolated_agents' => 0,
            'last_refreshed_at' => now()->toIso8601String(),
        ], now()->addHour());

        Cache::put("client:{$clientB->id}:huntress-security:v1", [
            'agents_total' => 99,
            'agents_unresponsive' => 9,
            'open_incidents' => 4,
            'edr_isolated_agents' => 2,
            'last_refreshed_at' => now()->toIso8601String(),
        ], now()->addHour());

        $service = app(HuntressClientMetricsService::class);
        $summaryA = $service->summaryForClient($clientA);
        $summaryB = $service->summaryForClient($clientB);

        $this->assertSame(10, $summaryA->agentsTotal);
        $this->assertSame(99, $summaryB->agentsTotal);
        $this->assertNotSame($summaryA->agentsTotal, $summaryB->agentsTotal);
    }

    public function test_api_failure_keeps_last_successful_cache(): void
    {
        $client = Client::factory()->create(['huntress_organization_id' => 'org-42']);

        Cache::put("client:{$client->id}:huntress-security:v1", [
            'agents_total' => 14,
            'agents_unresponsive' => 3,
            'open_incidents' => 2,
            'edr_isolated_agents' => 1,
            'last_refreshed_at' => now()->subMinutes(5)->toIso8601String(),
        ], now()->addHour());

        Http::fake([
            'https://api.huntress.io/v1/organizations/org-42' => Http::response(['error' => 'boom'], 500),
        ]);

        $summary = app(HuntressClientMetricsService::class)->refreshAndStore($client);

        $this->assertSame(14, $summary->agentsTotal);
        $this->assertSame(3, $summary->agentsUnresponsive);
        $this->assertSame(2, $summary->openIncidents);
        $this->assertSame(1, $summary->edrIsolatedAgents);
        $this->assertTrue($summary->isStale);
        $this->assertTrue($summary->available);
    }
}
