<?php

namespace Tests\Unit;

use App\Models\Client;
use App\Services\SuperOps\SuperOpsClientMetricsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SuperOpsClientMetricsServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.superops.api_token' => 'api-test-token',
            'services.superops.subdomain' => 'onitltd',
            'services.superops.region' => 'us',
            'services.superops.dashboard_cache_minutes' => 10,
            'services.superops.dashboard_stale_minutes' => 1440,
        ]);
    }

    public function test_refresh_computes_open_closed_and_date_ranges_from_client_tickets(): void
    {
        Http::fake([
            'https://api.superops.ai/msp' => Http::sequence()
                ->push([
                    'data' => [
                        'getTicketList' => [
                            'tickets' => [
                                [
                                    'ticketId' => '1',
                                    'displayId' => '1001',
                                    'subject' => 'Printer offline',
                                    'priority' => 'High',
                                    'status' => 'Open',
                                    'createdTime' => now()->subDays(2)->toIso8601String(),
                                    'resolutionTime' => null,
                                    'resolutionViolated' => null,
                                ],
                                [
                                    'ticketId' => '2',
                                    'displayId' => '1002',
                                    'subject' => 'VPN issue',
                                    'priority' => 'Normal',
                                    'status' => 'Waiting on Client',
                                    'createdTime' => now()->subDays(10)->toIso8601String(),
                                    'resolutionTime' => null,
                                    'resolutionViolated' => null,
                                ],
                                [
                                    'ticketId' => '3',
                                    'displayId' => '1003',
                                    'subject' => 'Closed ticket',
                                    'priority' => 'Low',
                                    'status' => 'Closed',
                                    'createdTime' => now()->subDays(40)->toIso8601String(),
                                    'resolutionTime' => now()->subDays(3)->toIso8601String(),
                                    'resolutionViolated' => false,
                                ],
                                [
                                    'ticketId' => '4',
                                    'displayId' => '1004',
                                    'subject' => 'Old closed',
                                    'priority' => 'Low',
                                    'status' => 'Closed (no response)',
                                    'createdTime' => now()->subDays(50)->toIso8601String(),
                                    'resolutionTime' => now()->subDays(20)->toIso8601String(),
                                    'resolutionViolated' => true,
                                ],
                                [
                                    'ticketId' => '5',
                                    'displayId' => '1005',
                                    'subject' => 'Mystery',
                                    'priority' => 'Normal',
                                    'status' => 'Mystery Status',
                                    'createdTime' => now()->subDays(1)->toIso8601String(),
                                    'resolutionTime' => null,
                                    'resolutionViolated' => null,
                                ],
                            ],
                            'listInfo' => ['totalCount' => 5, 'hasMore' => false],
                        ],
                    ],
                ])
                ->push([
                    'data' => [
                        'getAssetList' => [
                            'assets' => [
                                [
                                    'assetId' => 'a1',
                                    'name' => 'LAPTOP-PRO',
                                    'status' => 'ONLINE',
                                    'platform' => 'Microsoft Windows 11 Pro',
                                    'lastCommunicatedTime' => now()->toIso8601String(),
                                    'lastReportedTime' => now()->toIso8601String(),
                                    'sysUptime' => '2 hours 10 minutes',
                                    'patchStatus' => 'Fully Patched',
                                    'purchasedDate' => now()->subYears(2)->toDateString(),
                                ],
                                [
                                    'assetId' => 'a2',
                                    'name' => 'DESKTOP-HOME',
                                    'status' => 'OFFLINE',
                                    'platform' => 'Microsoft Windows 10 Home Single Language',
                                    'lastCommunicatedTime' => now()->subDays(40)->toIso8601String(),
                                    'lastReportedTime' => now()->subDays(40)->toIso8601String(),
                                    'sysUptime' => '2 days 1 hour',
                                    'patchStatus' => 'Missing Patches',
                                    'purchasedDate' => now()->subYears(6)->toDateString(),
                                ],
                            ],
                            'listInfo' => ['totalCount' => 23, 'hasMore' => false],
                        ],
                    ],
                ]),
        ]);

        $client = Client::factory()->create(['superops_account_id' => '6976691098608750592']);
        $summary = app(SuperOpsClientMetricsService::class)->refreshAndStore($client);

        $this->assertSame(23, $summary->assetsTotal);
        $this->assertSame(1, $summary->assetsOnline);
        $this->assertSame(1, $summary->assetsOffline);
        $this->assertSame(2, $summary->openTicketsTotal);
        $this->assertSame('High', array_key_first($summary->openTicketsByPriority));
        $this->assertCount(2, $summary->openTicketsTable);
        $this->assertSame(50, $summary->slaMetPercent);
        $this->assertSame(2, $summary->ticketsCreated['7']);
        $this->assertSame(3, $summary->ticketsCreated['14']);
        $this->assertSame(3, $summary->ticketsCreated['30']);
        $this->assertSame(5, $summary->ticketsCreated['all']);
        $this->assertSame(1, $summary->ticketsClosed['7']);
        $this->assertSame(1, $summary->ticketsClosed['14']);
        $this->assertSame(2, $summary->ticketsClosed['30']);
        $this->assertSame(2, $summary->ticketsClosed['all']);
        $this->assertSame(1, $summary->deviceInsights['offline_30d']['count']);
        $this->assertSame(['DESKTOP-HOME'], $summary->deviceInsights['offline_30d']['names']);
        $this->assertSame(1, $summary->deviceInsights['needs_restart']['count']);
        $this->assertSame(['DESKTOP-HOME'], $summary->deviceInsights['needs_restart']['names']);
        $this->assertSame(1, $summary->deviceInsights['edition']['home']);
        $this->assertSame(1, $summary->deviceInsights['edition']['pro']);
        $this->assertSame(1, $summary->deviceInsights['patch']['fully']);
        $this->assertSame(1, $summary->deviceInsights['patch']['not_fully']);
        $this->assertSame(1, $summary->deviceInsights['age']['under_3']);
        $this->assertSame(1, $summary->deviceInsights['age']['over_5']);
        $this->assertNotEmpty($summary->closedTicketsTable);

        Http::assertSent(function ($request) {
            $payload = $request->data();
            $condition = $payload['variables']['input']['condition'] ?? [];

            return str_contains($payload['query'], 'getTicketList')
                && str_contains($payload['query'], 'ticketId')
                && ($condition['attribute'] ?? null) === 'client.accountId'
                && ($condition['operator'] ?? null) === 'is'
                && ($condition['value'] ?? null) === '6976691098608750592'
                && ($payload['variables']['input']['sort'][0]['attribute'] ?? null) === 'createdTime'
                && $request->hasHeader('Authorization', 'Bearer api-test-token')
                && $request->hasHeader('CustomerSubDomain', 'onitltd');
        });

        Http::assertSent(function ($request) {
            $payload = $request->data();
            $condition = $payload['variables']['input']['condition'] ?? [];

            return str_contains($payload['query'], 'getAssetList')
                && str_contains($payload['query'], 'assetId')
                && ($condition['attribute'] ?? null) === 'client.accountId'
                && ($condition['value'] ?? null) === '6976691098608750592';
        });
    }

    public function test_cached_summary_is_isolated_per_client(): void
    {
        $clientA = Client::factory()->create(['superops_account_id' => '111']);
        $clientB = Client::factory()->create(['superops_account_id' => '222']);

        Cache::put("client:{$clientA->id}:superops-dashboard:v5", [
            'assets_total' => 10,
            'open_tickets_total' => 1,
            'tickets_created' => ['7' => 1, '14' => 1, '30' => 1, 'all' => 1],
            'tickets_closed' => ['7' => 0, '14' => 0, '30' => 0, 'all' => 0],
            'last_refreshed_at' => now()->toIso8601String(),
        ], now()->addHour());

        Cache::put("client:{$clientB->id}:superops-dashboard:v5", [
            'assets_total' => 99,
            'open_tickets_total' => 99,
            'tickets_created' => ['7' => 99, '14' => 99, '30' => 99, 'all' => 99],
            'tickets_closed' => ['7' => 99, '14' => 99, '30' => 99, 'all' => 99],
            'last_refreshed_at' => now()->toIso8601String(),
        ], now()->addHour());

        $service = app(SuperOpsClientMetricsService::class);
        $summaryA = $service->summaryForClient($clientA);
        $summaryB = $service->summaryForClient($clientB);

        $this->assertSame(10, $summaryA->assetsTotal);
        $this->assertSame(99, $summaryB->assetsTotal);
        $this->assertNotSame($summaryA->assetsTotal, $summaryB->assetsTotal);
    }

    public function test_api_failure_rethrows_and_keeps_last_successful_cache(): void
    {
        $client = Client::factory()->create(['superops_account_id' => '111']);

        Cache::put("client:{$client->id}:superops-dashboard:v5", [
            'assets_total' => 14,
            'open_tickets_total' => 6,
            'tickets_created' => ['7' => 3, '14' => 7, '30' => 12, 'all' => 184],
            'tickets_closed' => ['7' => 2, '14' => 6, '30' => 10, 'all' => 171],
            'last_refreshed_at' => now()->subMinutes(5)->toIso8601String(),
        ], now()->addHour());

        Http::fake([
            'https://api.superops.ai/msp' => Http::response(['errors' => [['message' => 'boom']]], 200),
        ]);

        $service = app(SuperOpsClientMetricsService::class);

        $threw = false;
        try {
            $service->refreshAndStore($client);
        } catch (\RuntimeException) {
            $threw = true;
        }
        $this->assertTrue($threw, 'refreshAndStore() should rethrow the upstream failure even when a cache exists.');

        // The last good snapshot is untouched and still served to page views.
        $summary = $service->summaryForClient($client);
        $this->assertSame(14, $summary->assetsTotal);
        $this->assertSame(6, $summary->openTicketsTotal);
    }

    public function test_job_records_failure_when_refresh_fails_after_a_successful_cache(): void
    {
        $client = Client::factory()->create(['superops_account_id' => '111']);

        Cache::put("client:{$client->id}:superops-dashboard:v5", [
            'assets_total' => 14,
            'open_tickets_total' => 6,
            'last_refreshed_at' => now()->subMinutes(5)->toIso8601String(),
        ], now()->addHour());

        Http::fake([
            'https://api.superops.ai/msp' => Http::response(['errors' => [['message' => 'boom']]], 200),
        ]);

        (new \App\Jobs\RefreshSuperOpsDashboardJob($client->id))->handle(app(SuperOpsClientMetricsService::class));

        $result = Cache::get('superops_dashboard.last_result.'.$client->id);
        $this->assertIsArray($result);
        $this->assertFalse($result['success']);
        $this->assertSame(14, Cache::get("client:{$client->id}:superops-dashboard:v5")['assets_total']);
    }

    public function test_page_view_serves_cache_without_requeueing_when_stale(): void
    {
        Bus::fake();

        $client = Client::factory()->create(['superops_account_id' => '111']);

        Cache::put("client:{$client->id}:superops-dashboard:v5", [
            'assets_total' => 14,
            'open_tickets_total' => 6,
            'tickets_created' => ['7' => 3, '14' => 7, '30' => 12, 'all' => 184],
            'tickets_closed' => ['7' => 2, '14' => 6, '30' => 10, 'all' => 171],
            'last_refreshed_at' => now()->subHours(3)->toIso8601String(),
        ], now()->addDays(7));

        $summary = app(SuperOpsClientMetricsService::class)->summaryForClient($client);

        $this->assertSame(14, $summary->assetsTotal);
        $this->assertTrue($summary->isStale);
        Bus::assertNotDispatched(\App\Jobs\RefreshSuperOpsDashboardJob::class);
    }

    public function test_needs_background_refresh_uses_refresh_after_not_client_banner_ttl(): void
    {
        config([
            'services.superops.dashboard_cache_minutes' => 180,
        ]);

        $service = app(SuperOpsClientMetricsService::class);
        $client = Client::factory()->create(['superops_account_id' => '111']);

        Cache::put("client:{$client->id}:superops-dashboard:v5", [
            'assets_total' => 14,
            'open_tickets_total' => 6,
            'tickets_created' => ['7' => 3, '14' => 7, '30' => 12, 'all' => 184],
            'tickets_closed' => ['7' => 2, '14' => 6, '30' => 10, 'all' => 171],
            'last_refreshed_at' => now()->subHours(2)->toIso8601String(),
        ], now()->addDay());

        $this->assertTrue($service->needsBackgroundRefresh($client));
        $this->assertFalse($service->summaryForClient($client)->isStale);

        Cache::put("client:{$client->id}:superops-dashboard:v5", [
            'assets_total' => 14,
            'open_tickets_total' => 6,
            'tickets_created' => ['7' => 3, '14' => 7, '30' => 12, 'all' => 184],
            'tickets_closed' => ['7' => 2, '14' => 6, '30' => 10, 'all' => 171],
            'last_refreshed_at' => now()->subMinutes(6)->toIso8601String(),
        ], now()->addDay());

        $this->assertFalse($service->needsBackgroundRefresh($client));
    }

    public function test_api_client_strips_bearer_prefix_from_env_token(): void
    {
        config(['services.superops.api_token' => 'Bearer api-pasted-token']);

        Http::fake([
            'https://api.superops.ai/msp' => Http::response([
                'data' => [
                    'getClientList' => [
                        'clients' => [],
                        'listInfo' => ['totalCount' => 0],
                    ],
                ],
            ], 200),
        ]);

        app(\App\Services\SuperOps\SuperOpsApiClient::class)->query(
            'query getClientList($input: ListInfoInput!) { getClientList(input: $input) { clients { accountId } listInfo { totalCount } } }',
            ['input' => ['page' => 1, 'pageSize' => 1]],
        );

        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer api-pasted-token'));
    }
}
