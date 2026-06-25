<?php

namespace Tests\Unit;

use App\Enums\EntraIdentityType;
use App\Models\Client;
use App\Services\EntraSync\MicrosoftGraphClient;
use App\Services\M365\M365DirectoryService;
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

    public function test_refresh_bypasses_cached_snapshot(): void
    {
        Cache::flush();

        $client = Client::factory()->create([
            'entra_tenant_id' => '11111111-1111-1111-1111-111111111111',
        ]);

        $graph = Mockery::mock(MicrosoftGraphClient::class);
        $graph->shouldReceive('isConfigured')->andReturn(true);
        $graph->shouldReceive('listSyncEligibleUsers')
            ->twice()
            ->andReturn(
                [[
                    'id' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa',
                    'mail' => 'first@acme.com',
                    'userPrincipalName' => 'first@acme.com',
                    'displayName' => 'First User',
                    'accountEnabled' => true,
                    'identityType' => EntraIdentityType::User,
                ]],
                [[
                    'id' => 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb',
                    'mail' => 'second@acme.com',
                    'userPrincipalName' => 'second@acme.com',
                    'displayName' => 'Second User',
                    'accountEnabled' => true,
                    'identityType' => EntraIdentityType::User,
                ]],
            );
        $graph->shouldReceive('listTenantGroups')->twice()->andReturn([]);
        $graph->shouldReceive('getUserLicenseSkuPartNumbers')->andReturn(['O365_BUSINESS']);

        $service = new M365DirectoryService($graph);

        $first = $service->snapshot($client);
        $cached = $service->snapshot($client);

        $this->assertSame('First User (User)', $first->people->first()['displayName']);
        $this->assertSame('First User (User)', $cached->people->first()['displayName']);

        $refreshed = $service->snapshot($client, refresh: true);

        $this->assertSame('Second User (User)', $refreshed->people->first()['displayName']);
    }
}
