<?php

namespace Tests\Unit;

use App\Models\Client;
use App\Services\EntraSync\MicrosoftGraphClient;
use App\Services\M365\M365DirectorySnapshot;
use App\Services\M365\M365InsightsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class M365InsightsServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config([
            'services.entra_sync.client_id' => 'client-id',
            'services.entra_sync.client_secret' => 'client-secret',
        ]);
    }

    public function test_refresh_builds_license_inventory_and_reuses_directory_snapshot(): void
    {
        $client = Client::factory()->create([
            'entra_tenant_id' => '11111111-1111-1111-1111-111111111111',
        ]);

        Cache::put('m365_directory.client.'.$client->id, new M365DirectorySnapshot(
            collect([
                ['type' => 'user'],
                ['type' => 'user'],
                ['type' => 'shared_mailbox'],
            ]),
            collect(),
            now(),
        ));

        Http::fake([
            'https://login.microsoftonline.com/*' => Http::response(['access_token' => 'token']),
            'https://graph.microsoft.com/v1.0/subscribedSkus*' => Http::response([
                'value' => [
                    [
                        'skuId' => 'sku-1',
                        'skuPartNumber' => 'M365_BUSINESS_PREMIUM',
                        'consumedUnits' => 12,
                        'prepaidUnits' => ['enabled' => 15],
                        'appliesTo' => 'User',
                        'capabilityStatus' => 'Enabled',
                    ],
                    [
                        'skuId' => 'sku-2',
                        'skuPartNumber' => 'ENTERPRISEPACK',
                        'consumedUnits' => 8,
                        'prepaidUnits' => ['enabled' => 10],
                        'appliesTo' => 'User',
                        'capabilityStatus' => 'Enabled',
                    ],
                    [
                        'skuId' => 'sku-3',
                        'skuPartNumber' => 'DISABLED_SKU',
                        'consumedUnits' => 50,
                        'prepaidUnits' => ['enabled' => 50],
                        'appliesTo' => 'User',
                        'capabilityStatus' => 'Suspended',
                    ],
                    [
                        'skuId' => 'sku-4',
                        'skuPartNumber' => 'COMPANY_SKU',
                        'consumedUnits' => 1,
                        'prepaidUnits' => ['enabled' => 1],
                        'appliesTo' => 'Company',
                        'capabilityStatus' => 'Enabled',
                    ],
                ],
            ]),
        ]);

        $summary = (new M365InsightsService(new MicrosoftGraphClient))->refreshAndStore($client);

        $this->assertSame(2, $summary->licensedUserCount);
        $this->assertSame(25, $summary->totalSeatsPurchased);
        $this->assertSame(20, $summary->totalSeatsAssigned);
        $this->assertSame(80.0, $summary->overallUtilizationPct);
        $this->assertSame('M365_BUSINESS_PREMIUM', $summary->topSkus[0]['skuPartNumber']);
        $this->assertTrue($summary->hasData());

        Http::assertNotSent(fn (Request $request): bool => str_starts_with(
            $request->url(),
            'https://graph.microsoft.com/v1.0/users',
        ));
        Http::assertSent(function (Request $request): bool {
            return str_starts_with($request->url(), 'https://graph.microsoft.com/v1.0/subscribedSkus')
                && str_contains(urldecode($request->url()), '$select=skuId,skuPartNumber,consumedUnits,prepaidUnits,appliesTo,capabilityStatus');
        });
    }

    public function test_refresh_counts_licensed_users_from_graph_when_directory_cache_is_missing(): void
    {
        $client = Client::factory()->create([
            'entra_tenant_id' => '22222222-2222-2222-2222-222222222222',
        ]);

        Http::fake([
            'https://login.microsoftonline.com/*' => Http::response(['access_token' => 'token']),
            'https://graph.microsoft.com/v1.0/subscribedSkus*' => Http::response(['value' => []]),
            'https://graph.microsoft.com/v1.0/users*' => Http::response([
                'value' => [
                    [
                        'id' => 'licensed-user',
                        'accountEnabled' => true,
                        'assignedLicenses' => [['skuId' => 'sku-1']],
                    ],
                    [
                        'id' => 'unlicensed-user',
                        'accountEnabled' => true,
                        'assignedLicenses' => [],
                    ],
                ],
            ]),
        ]);

        $summary = (new M365InsightsService(new MicrosoftGraphClient))->refreshAndStore($client);

        $this->assertSame(1, $summary->licensedUserCount);
        $this->assertSame(0, $summary->totalSeatsPurchased);
        $this->assertSame(0.0, $summary->overallUtilizationPct);
    }
}
