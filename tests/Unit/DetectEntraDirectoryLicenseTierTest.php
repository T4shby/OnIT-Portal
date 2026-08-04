<?php

namespace Tests\Unit;

use App\Services\EntraSync\MicrosoftGraphClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DetectEntraDirectoryLicenseTierTest extends TestCase
{
    public function test_business_premium_spb_is_detected_as_p1(): void
    {
        config([
            'services.entra_sync.client_id' => 'app-id',
            'services.entra_sync.client_secret' => 'secret',
        ]);

        Http::fake([
            'https://login.microsoftonline.com/*/oauth2/v2.0/token' => Http::response([
                'access_token' => 'token',
                'expires_in' => 3600,
            ]),
            'https://graph.microsoft.com/v1.0/subscribedSkus*' => Http::response([
                'value' => [
                    ['skuId' => 'sku-1', 'skuPartNumber' => 'SPB'],
                    ['skuId' => 'sku-2', 'skuPartNumber' => 'FLOW_FREE'],
                ],
            ]),
        ]);

        $tier = app(MicrosoftGraphClient::class)
            ->detectEntraDirectoryLicenseTier('d6017e9f-4aba-43f1-94c1-56d3b9051f6f');

        $this->assertSame('p1', $tier);
    }

    public function test_only_flow_free_is_free_tier(): void
    {
        config([
            'services.entra_sync.client_id' => 'app-id',
            'services.entra_sync.client_secret' => 'secret',
        ]);

        Http::fake([
            'https://login.microsoftonline.com/*/oauth2/v2.0/token' => Http::response([
                'access_token' => 'token',
                'expires_in' => 3600,
            ]),
            'https://graph.microsoft.com/v1.0/subscribedSkus*' => Http::response([
                'value' => [
                    ['skuId' => 'sku-1', 'skuPartNumber' => 'FLOW_FREE'],
                ],
            ]),
        ]);

        $tier = app(MicrosoftGraphClient::class)
            ->detectEntraDirectoryLicenseTier('d6017e9f-4aba-43f1-94c1-56d3b9051f6f');

        $this->assertSame('free', $tier);
    }
}
