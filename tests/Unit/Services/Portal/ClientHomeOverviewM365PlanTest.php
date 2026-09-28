<?php

namespace Tests\Unit\Services\Portal;

use App\Enums\UserRole;
use App\Models\Client;
use App\Models\User;
use App\Services\M365\M365InsightsSummary;
use App\Services\Portal\ClientHomeOverviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

class ClientHomeOverviewM365PlanTest extends TestCase
{
    use RefreshDatabase;

    public function test_plan_metric_uses_licence_display_name_not_part_number(): void
    {
        config([
            'services.entra_sync.client_id' => 'entra-sync-test-client-id',
            'services.entra_sync.client_secret' => 'entra-sync-test-client-secret',
        ]);

        $client = Client::factory()->create([
            'entra_tenant_id' => '11111111-1111-1111-1111-111111111111',
            'product_entitlements' => ['m365' => ['entitled' => true]],
        ]);
        $admin = User::factory()->create(['role' => UserRole::ClientAdmin, 'client_id' => $client->id]);

        // Same row shape M365InsightsService::summaryFromCache() produces.
        $summary = new M365InsightsSummary(
            licensedUserCount: 10,
            totalSeatsPurchased: 12,
            totalSeatsAssigned: 10,
            overallUtilizationPct: 83.3,
            topSkus: [[
                'skuPartNumber' => 'SPB',
                'displayName' => 'Microsoft 365 Business Premium',
                'purchased' => 12,
                'assigned' => 10,
                'utilizationPct' => 83.3,
                'countsTowardUtilisation' => true,
            ]],
            lastRefreshedAt: now(),
            isStale: false,
            refreshInProgress: false,
            unavailableReason: null,
        );

        $method = new ReflectionMethod(ClientHomeOverviewService::class, 'm365Column');
        $method->setAccessible(true);
        $column = $method->invoke(app(ClientHomeOverviewService::class), $client, $admin, $summary, true);

        $plan = collect($column['metrics'])->firstWhere('label', 'Plan');
        $this->assertSame('live', $column['state']);
        $this->assertSame('Microsoft 365 Business Premium', $plan['value']);
    }
}
