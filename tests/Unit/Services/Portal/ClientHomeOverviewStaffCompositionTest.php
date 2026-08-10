<?php

namespace Tests\Unit\Services\Portal;

use App\Models\Client;
use App\Services\Portal\ClientHomeOverviewService;
use App\Services\Portal\ClientProductService;
use Tests\TestCase;

class ClientHomeOverviewStaffCompositionTest extends TestCase
{
    public function test_support_led_when_huntress_not_sold(): void
    {
        $client = new Client([
            'name' => 'Support Co',
            'superops_account_id' => 'so-1',
            'product_entitlements' => [
                ClientProductService::KEY_SUPEROPS => ['entitled' => true],
                ClientProductService::KEY_HUNTRESS => ['entitled' => false],
                ClientProductService::KEY_DROPSUITE => ['entitled' => false],
                ClientProductService::KEY_M365 => ['entitled' => false],
            ],
        ]);

        /** @var ClientHomeOverviewService $svc */
        $svc = $this->app->make(ClientHomeOverviewService::class);
        $home = $svc->staffHomeComposition($client);

        $this->assertSame('support_led', $home['value_strip_mode']);
        $this->assertSame(['Tickets resolved', 'Open tickets', 'SLA met'], $home['value_strip_labels']);
        $this->assertStringContainsString('Support-led', $home['headline']);

        $hunt = collect($home['columns'])->firstWhere('key', 'huntress');
        $this->assertNotNull($hunt);
        $this->assertSame('not_sold', $hunt['status']);
        $this->assertSame('Add-on', $hunt['client_card_label']);
    }

    public function test_mdr_included_when_huntress_sold(): void
    {
        $client = new Client([
            'name' => 'MDR Co',
            'superops_account_id' => 'so-1',
            'huntress_organization_id' => 'hr-1',
            'product_entitlements' => [
                ClientProductService::KEY_SUPEROPS => ['entitled' => true],
                ClientProductService::KEY_HUNTRESS => ['entitled' => true],
            ],
        ]);

        /** @var ClientHomeOverviewService $svc */
        $svc = $this->app->make(ClientHomeOverviewService::class);
        $home = $svc->staffHomeComposition($client);

        $this->assertSame('mdr_included', $home['value_strip_mode']);
        $this->assertContains('Threats stopped (MDR)', $home['value_strip_labels']);
        $this->assertStringContainsString('MDR', $home['headline']);
    }
}
