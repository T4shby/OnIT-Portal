<?php

namespace Tests\Unit\Services\Portal;

use App\Models\Client;
use App\Models\ClientMetricDailySnapshot;
use App\Services\Portal\ClientMetricSnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientMetricSnapshotServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_captures_and_compares_previous_month(): void
    {
        $client = Client::factory()->create();
        $service = $this->app->make(ClientMetricSnapshotService::class);

        $service->captureBundle($client, [
            'overall_band' => 'ok',
            'value' => ['threats' => '3', 'resolved' => '12', 'sla' => '98%'],
            'columns' => [
                [
                    'key' => 'superops',
                    'tone' => 'ok',
                    'status_label' => 'Healthy',
                    'status_reason' => 'Fine',
                    'metrics' => [
                        ['label' => 'Open tickets', 'value' => '2', 'kind' => 'ok'],
                    ],
                ],
            ],
        ], now()->subMonthNoOverflow()->endOfMonth());

        $compare = $service->previousMonthCompare($client);

        $this->assertTrue($compare['available']);
        $this->assertSame('ready', $compare['status']);
        $this->assertSame('3', $compare['value']['threats'] ?? null);
        $this->assertSame('2', $compare['services']['superops']['metrics']['Open tickets'] ?? null);
        $this->assertSame(1, ClientMetricDailySnapshot::query()->count());
    }
}
