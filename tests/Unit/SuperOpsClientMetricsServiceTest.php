<?php

namespace Tests\Unit;

use App\Models\Client;
use App\Services\SuperOps\SuperOpsClientMetricsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            'services.superops.api_token' => 'test-token',
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
                                    'client' => ['accountId' => '6976691098608750592', 'name' => '3R Systems Limited'],
                                    'status' => 'Open',
                                    'createdTime' => now()->subDays(2)->toIso8601String(),
                                    'resolutionTime' => null,
                                ],
                                [
                                    'client' => ['accountId' => '6976691098608750592', 'name' => '3R Systems Limited'],
                                    'status' => 'Waiting on Client',
                                    'createdTime' => now()->subDays(10)->toIso8601String(),
                                    'resolutionTime' => null,
                                ],
                                [
                                    'client' => ['accountId' => '6976691098608750592', 'name' => '3R Systems Limited'],
                                    'status' => 'Closed',
                                    'createdTime' => now()->subDays(40)->toIso8601String(),
                                    'resolutionTime' => now()->subDays(3)->toIso8601String(),
                                ],
                                [
                                    'client' => ['accountId' => '6976691098608750592', 'name' => '3R Systems Limited'],
                                    'status' => 'Closed (no response)',
                                    'createdTime' => now()->subDays(50)->toIso8601String(),
                                    'resolutionTime' => now()->subDays(20)->toIso8601String(),
                                ],
                                [
                                    'client' => ['accountId' => '6976691098608750592', 'name' => '3R Systems Limited'],
                                    'status' => 'Mystery Status',
                                    'createdTime' => now()->subDays(1)->toIso8601String(),
                                    'resolutionTime' => null,
                                ],
                                [
                                    'client' => ['accountId' => 'other-client', 'name' => 'Other Client'],
                                    'status' => 'Open',
                                    'createdTime' => now()->subDays(1)->toIso8601String(),
                                    'resolutionTime' => null,
                                ],
                            ],
                            'listInfo' => ['totalCount' => 6, 'page' => 1, 'pageSize' => 100],
                        ],
                    ],
                ])
                ->push([
                    'data' => [
                        'getAssetList' => [
                            'assets' => array_merge(
                                array_fill(0, 23, ['client' => ['accountId' => '6976691098608750592']]),
                                [['client' => ['accountId' => 'other-client']]]
                            ),
                            'listInfo' => ['totalCount' => 24, 'page' => 1, 'pageSize' => 100],
                        ],
                    ],
                ]),
        ]);

        $client = Client::factory()->create(['superops_account_id' => '6976691098608750592']);
        $summary = app(SuperOpsClientMetricsService::class)->refreshAndStore($client);

        $this->assertSame(23, $summary->assetsTotal);
        $this->assertSame(2, $summary->openTicketsTotal);
        $this->assertSame(2, $summary->ticketsCreated['7']);
        $this->assertSame(3, $summary->ticketsCreated['14']);
        $this->assertSame(3, $summary->ticketsCreated['30']);
        $this->assertSame(5, $summary->ticketsCreated['all']);
        $this->assertSame(1, $summary->ticketsClosed['7']);
        $this->assertSame(1, $summary->ticketsClosed['14']);
        $this->assertSame(2, $summary->ticketsClosed['30']);
        $this->assertSame(2, $summary->ticketsClosed['all']);

        Http::assertSent(function ($request) {
            $payload = $request->data();

            return str_contains($payload['query'], 'getTicketList')
                && ! isset($payload['variables']['input']['condition'])
                && ($payload['variables']['input']['sort']['attribute'] ?? null) === 'displayID';
        });

        Http::assertSent(function ($request) {
            $payload = $request->data();

            return str_contains($payload['query'], 'getAssetList')
                && ! isset($payload['variables']['input']['condition']);
        });
    }

    public function test_cached_summary_is_isolated_per_client(): void
    {
        $clientA = Client::factory()->create(['superops_account_id' => '111']);
        $clientB = Client::factory()->create(['superops_account_id' => '222']);

        Cache::put("client:{$clientA->id}:superops-dashboard:v1", [
            'assets_total' => 10,
            'open_tickets_total' => 1,
            'tickets_created' => ['7' => 1, '14' => 1, '30' => 1, 'all' => 1],
            'tickets_closed' => ['7' => 0, '14' => 0, '30' => 0, 'all' => 0],
            'last_refreshed_at' => now()->toIso8601String(),
        ], now()->addHour());

        Cache::put("client:{$clientB->id}:superops-dashboard:v1", [
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

    public function test_api_failure_keeps_last_successful_cache(): void
    {
        $client = Client::factory()->create(['superops_account_id' => '111']);

        Cache::put("client:{$client->id}:superops-dashboard:v1", [
            'assets_total' => 14,
            'open_tickets_total' => 6,
            'tickets_created' => ['7' => 3, '14' => 7, '30' => 12, 'all' => 184],
            'tickets_closed' => ['7' => 2, '14' => 6, '30' => 10, 'all' => 171],
            'last_refreshed_at' => now()->subMinutes(5)->toIso8601String(),
        ], now()->addHour());

        Http::fake([
            'https://api.superops.ai/msp' => Http::response(['errors' => [['message' => 'boom']]], 200),
        ]);

        $summary = app(SuperOpsClientMetricsService::class)->refreshAndStore($client);

        $this->assertSame(14, $summary->assetsTotal);
        $this->assertSame(6, $summary->openTicketsTotal);
        $this->assertTrue($summary->isStale);
    }
}
