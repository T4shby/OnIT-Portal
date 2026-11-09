<?php

namespace Tests\Unit;

use App\Models\Client;
use App\Models\ClientDomain;
use App\Services\MailDns\DkimSigningStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DkimSigningStatusServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.entra_sync.client_id' => 'test-client',
            'services.entra_sync.client_secret' => 'test-secret',
        ]);
    }

    public function test_reads_every_domain_from_exchange_and_ticks_when_all_portal_domains_sign(): void
    {
        Http::fake([
            'login.microsoftonline.com/*' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            'graph.microsoft.com/beta/admin/exchange/dkimSigningConfigs*' => Http::response([], 404),
            'outlook.office365.com/adminapi/beta/*/DkimSigningConfig*' => Http::response([
                'value' => [
                    [
                        'Domain' => 'bray-interiors.co.uk',
                        'Enabled' => true,
                        'Status' => 'Valid',
                    ],
                    [
                        'Domain' => 'braybuildingservices.co.uk',
                        'Enabled' => true,
                        'Status' => 'Valid',
                    ],
                    [
                        'Domain' => 'interiorsbydanieljames.onmicrosoft.com',
                        'Enabled' => true,
                        'Status' => 'Valid',
                    ],
                ],
            ], 200),
        ]);

        $client = Client::factory()->create([
            'primary_domain' => 'bray-interiors.co.uk',
            'entra_tenant_id' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa',
            'onboarding_checklist' => [],
        ]);
        ClientDomain::query()->create([
            'client_id' => $client->id,
            'domain' => 'bray-interiors.co.uk',
            'is_primary' => true,
            'source' => 'manual',
        ]);
        ClientDomain::query()->create([
            'client_id' => $client->id,
            'domain' => 'braybuildingservices.co.uk',
            'is_primary' => false,
            'source' => 'manual',
        ]);
        $client->load('domains');

        $svc = app(DkimSigningStatusService::class);
        $summary = $svc->summaryForClient($client, fresh: true);
        $svc->syncChecklist($client, $summary);

        $this->assertTrue($summary['available']);
        $this->assertTrue($summary['portal_custom_signing']);
        $this->assertCount(3, $summary['configs']);
        $this->assertTrue($client->fresh()->onboarding_checklist['mail_dkim_signing_enabled']);
    }

    public function test_does_not_tick_when_a_portal_domain_is_not_signing(): void
    {
        Http::fake([
            'login.microsoftonline.com/*' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            'graph.microsoft.com/beta/admin/exchange/dkimSigningConfigs*' => Http::response([], 404),
            'outlook.office365.com/adminapi/beta/*/DkimSigningConfig*' => Http::response([
                'value' => [
                    [
                        'Domain' => 'bray-interiors.co.uk',
                        'Enabled' => true,
                        'Status' => 'Valid',
                    ],
                    [
                        'Domain' => 'braybuildingservices.co.uk',
                        'Enabled' => false,
                        'Status' => 'CnameMissing',
                    ],
                ],
            ], 200),
        ]);

        $client = Client::factory()->create([
            'primary_domain' => 'bray-interiors.co.uk',
            'entra_tenant_id' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa',
            'onboarding_checklist' => ['mail_dkim_signing_enabled' => true],
        ]);
        ClientDomain::query()->create([
            'client_id' => $client->id,
            'domain' => 'bray-interiors.co.uk',
            'is_primary' => true,
            'source' => 'manual',
        ]);
        ClientDomain::query()->create([
            'client_id' => $client->id,
            'domain' => 'braybuildingservices.co.uk',
            'is_primary' => false,
            'source' => 'manual',
        ]);
        $client->load('domains');

        $svc = app(DkimSigningStatusService::class);
        $summary = $svc->summaryForClient($client, fresh: true);
        $svc->syncChecklist($client, $summary);

        $this->assertTrue($summary['available']);
        $this->assertFalse($summary['portal_custom_signing']);
        $this->assertFalse($client->fresh()->onboarding_checklist['mail_dkim_signing_enabled']);
    }

    public function test_permission_missing_leaves_manual_tick_in_place(): void
    {
        Http::fake([
            'login.microsoftonline.com/*' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            'graph.microsoft.com/beta/admin/exchange/dkimSigningConfigs*' => Http::response(['error' => 'forbidden'], 403),
            'outlook.office365.com/adminapi/beta/*/DkimSigningConfig*' => Http::response(['error' => 'forbidden'], 403),
        ]);

        $client = Client::factory()->create([
            'primary_domain' => 'bray-interiors.co.uk',
            'entra_tenant_id' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa',
            'onboarding_checklist' => ['mail_dkim_signing_enabled' => true],
        ]);

        $svc = app(DkimSigningStatusService::class);
        $summary = $svc->summaryForClient($client, fresh: true);
        $svc->syncChecklist($client, $summary);

        $this->assertFalse($summary['available']);
        $this->assertSame('dkim_signing_permission_missing', $summary['reason']);
        $this->assertTrue($client->fresh()->onboarding_checklist['mail_dkim_signing_enabled']);
    }
}
