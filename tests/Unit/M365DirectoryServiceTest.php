<?php

namespace Tests\Unit;

use App\Enums\EntraIdentityType;
use App\Models\Client;
use App\Services\EntraSync\MicrosoftGraphClient;
use App\Services\M365\M365DirectoryService;
use App\Services\M365\M365DirectorySnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

class M365DirectoryServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_stale_snapshot_is_served_without_rebuilding(): void
    {
        Cache::flush();

        $client = Client::factory()->create([
            'entra_tenant_id' => '11111111-1111-1111-1111-111111111111',
        ]);

        $graph = Mockery::mock(MicrosoftGraphClient::class);
        $graph->shouldReceive('isConfigured')->andReturn(true);
        $graph->shouldNotReceive('listSyncEligibleUsers');

        $snapshot = new M365DirectorySnapshot(
            collect([['displayName' => 'Cached User', 'email' => 'a@b.com', 'type' => 'user', 'typeLabel' => 'User', 'accountEnabled' => true, 'licenses' => [], 'portalLogin' => true]]),
            collect(),
            now()->subHour(),
        );

        Cache::put('m365_directory.client.'.$client->id, $snapshot, now()->addHour());
        Cache::put('m365_directory.meta.'.$client->id, [
            'refreshed_at' => $snapshot->refreshedAt->toIso8601String(),
        ], now()->addHour());

        $service = new M365DirectoryService($graph);
        $display = $service->displaySnapshot($client);

        $this->assertSame('Cached User', $display->snapshot?->people->first()['displayName']);
        $this->assertTrue($display->isStale);
    }

    public function test_snapshot_reuses_license_skus_from_eligible_user_listing(): void
    {
        Cache::flush();

        $client = Client::factory()->create([
            'entra_tenant_id' => '11111111-1111-1111-1111-111111111111',
        ]);

        $graph = Mockery::mock(MicrosoftGraphClient::class);
        $graph->shouldReceive('listSyncEligibleUsers')
            ->once()
            ->with($client->entra_tenant_id)
            ->andReturn([[
                'id' => 'user-1',
                'mail' => 'licensed@example.com',
                'userPrincipalName' => 'licensed@example.com',
                'displayName' => 'Licensed User',
                'accountEnabled' => true,
                'identityType' => EntraIdentityType::User,
                'licenseSkuPartNumbers' => ['O365_BUSINESS'],
            ]]);
        $graph->shouldReceive('listTenantGroups')
            ->once()
            ->with($client->entra_tenant_id)
            ->andReturn([]);
        $graph->shouldNotReceive('getUserLicenseSkuPartNumbers');

        $snapshot = (new M365DirectoryService($graph))->buildAndStoreSnapshot($client);

        $this->assertSame(['O365_BUSINESS'], $snapshot->people->first()['licenses']);
    }
}
