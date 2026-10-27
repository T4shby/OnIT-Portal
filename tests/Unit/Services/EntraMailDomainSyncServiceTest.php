<?php

namespace Tests\Unit\Services;

use App\Models\Client;
use App\Models\ClientDomain;
use App\Services\EntraSync\MicrosoftGraphClient;
use App\Services\MailDns\ClientDomainService;
use App\Services\MailDns\EntraMailDomainSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EntraMailDomainSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeds_primary_from_verified_default_domain(): void
    {
        Bus::fake();
        config([
            'services.entra_sync.client_id' => 'test-client',
            'services.entra_sync.client_secret' => 'test-secret',
        ]);

        Http::fake([
            'login.microsoftonline.com/*' => Http::response([
                'access_token' => 'tok',
                'expires_in' => 3600,
            ]),
            'graph.microsoft.com/v1.0/domains*' => Http::response([
                'value' => [
                    [
                        'id' => 'yorpower.onmicrosoft.com',
                        'isVerified' => true,
                        'isDefault' => false,
                        'isInitial' => true,
                    ],
                    [
                        'id' => 'yorpower.com',
                        'isVerified' => true,
                        'isDefault' => true,
                        'isInitial' => false,
                    ],
                ],
            ]),
            'graph.microsoft.com/v1.0/users*' => Http::response([
                'value' => [
                    ['mail' => 'a@yorpower.com', 'userPrincipalName' => 'a@yorpower.com'],
                ],
            ]),
        ]);

        $client = Client::factory()->create([
            'name' => 'YorPower',
            'entra_tenant_id' => '11111111-1111-1111-1111-111111111111',
            'primary_domain' => null,
        ]);

        $result = app(EntraMailDomainSyncService::class)->sync($client);

        $this->assertTrue($result['ok']);
        $this->assertTrue($result['seeded_primary']);
        $this->assertSame('yorpower.com', $client->fresh()->primary_domain);
        $this->assertDatabaseHas('client_domains', [
            'client_id' => $client->id,
            'domain' => 'yorpower.com',
            'is_primary' => 1,
            'source' => 'entra',
        ]);
    }

    public function test_falls_back_to_mailbox_suffix_when_domains_api_denied(): void
    {
        Bus::fake();
        config([
            'services.entra_sync.client_id' => 'test-client',
            'services.entra_sync.client_secret' => 'test-secret',
        ]);

        Http::fake([
            'login.microsoftonline.com/*' => Http::response([
                'access_token' => 'tok',
                'expires_in' => 3600,
            ]),
            'graph.microsoft.com/v1.0/domains*' => Http::response(['error' => ['message' => 'Forbidden']], 403),
            'graph.microsoft.com/v1.0/users*' => Http::response([
                'value' => [
                    ['mail' => 'one@clientco.co.uk', 'userPrincipalName' => 'one@clientco.co.uk'],
                    ['mail' => 'two@clientco.co.uk', 'userPrincipalName' => 'two@clientco.onmicrosoft.com'],
                    ['mail' => 'three@other.co.uk', 'userPrincipalName' => 'three@other.co.uk'],
                ],
            ]),
        ]);

        $client = Client::factory()->create([
            'entra_tenant_id' => '22222222-2222-2222-2222-222222222222',
            'primary_domain' => null,
        ]);

        $result = app(EntraMailDomainSyncService::class)->sync($client);

        $this->assertTrue($result['ok']);
        $this->assertTrue($result['seeded_primary']);
        $this->assertSame('clientco.co.uk', $client->fresh()->primary_domain);
        $this->assertNotNull($result['domains_api_error']);
        $this->assertDatabaseHas('client_domains', [
            'client_id' => $client->id,
            'domain' => 'clientco.co.uk',
            'source' => 'entra_mail',
        ]);
    }

    public function test_cross_check_flags_portal_only_and_entra_only(): void
    {
        $client = Client::factory()->create(['primary_domain' => 'portal-only.example']);
        ClientDomain::query()->create([
            'client_id' => $client->id,
            'domain' => 'portal-only.example',
            'is_primary' => true,
            'source' => 'manual',
        ]);

        $service = app(EntraMailDomainSyncService::class);
        $cross = $service->crossCheck(
            $client,
            [['domain' => 'entra-only.example', 'is_default' => true, 'is_initial' => false, 'is_onmicrosoft' => false]],
            [['domain' => 'portal-only.example', 'mailbox_count' => 2]],
        );

        $this->assertSame(['entra-only.example'], $cross['entra_only']);
        $this->assertSame(['portal-only.example'], $cross['both']);
        $this->assertSame([], $cross['portal_only']);
    }
}
