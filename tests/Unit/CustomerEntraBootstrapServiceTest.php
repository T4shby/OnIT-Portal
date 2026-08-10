<?php

namespace Tests\Unit;

use App\Models\Client;
use App\Services\ClientOnboardingService;
use App\Services\EntraSync\CustomerEntraBootstrapService;
use App\Services\EntraSync\MicrosoftGraphClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class CustomerEntraBootstrapServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_bootstrap_saves_tenant_licence_group_and_app_ids(): void
    {
        $client = Client::factory()->create([
            'name' => 'We Are Find',
            'entra_tenant_id' => null,
            'entra_group_id' => null,
            'entra_license_tier' => 'free',
        ]);

        $tenantId = 'd6017e9f-4aba-43f1-94c1-56d3b9051f6f';
        $groupId = '493a4f92-717a-435e-ae4a-466532745c32';
        $scimAppId = '11111111-1111-1111-1111-111111111111';
        $ssoAppId = '22222222-2222-2222-2222-222222222222';
        $scimObjectId = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';
        $ssoObjectId = 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb';
        $scimSpId = 'cccccccc-cccc-cccc-cccc-cccccccccccc';
        $ssoSpId = 'dddddddd-dddd-dddd-dddd-dddddddddddd';
        $roleId = 'eeeeeeee-eeee-eeee-eeee-eeeeeeeeeeee';

        $graph = Mockery::mock(MicrosoftGraphClient::class);
        $graph->shouldReceive('isConfigured')->andReturn(true);
        $graph->shouldReceive('clearAccessTokenCache')->with($tenantId);
        $graph->shouldReceive('waitUntilAppOnlyGraphReady')
            ->once()
            ->with($tenantId)
            ->andReturn(['ready' => true, 'attempts' => 1, 'last_error' => null]);
        $graph->shouldReceive('retryAfterConsentPropagation')
            ->times(4)
            ->andReturnUsing(function (string $tid, callable $op) {
                return $op();
            });
        $graph->shouldReceive('detectEntraDirectoryLicenseTier')->once()->with($tenantId)->andReturn('p1');
        $graph->shouldReceive('ensurePortalSecurityGroup')
            ->once()
            ->with($tenantId, 'On IT Portal - We Are Find')
            ->andReturn($groupId);
        $graph->shouldReceive('ensureNamedEnterpriseApplication')
            ->once()
            ->with($tenantId, 'SuperOps - We Are Find')
            ->andReturn([
                'appId' => $scimAppId,
                'applicationObjectId' => $scimObjectId,
                'servicePrincipalId' => $scimSpId,
            ]);
        $graph->shouldReceive('ensureNamedEnterpriseApplication')
            ->once()
            ->with($tenantId, 'SuperOps Requester SSO - We Are Find')
            ->andReturn([
                'appId' => $ssoAppId,
                'applicationObjectId' => $ssoObjectId,
                'servicePrincipalId' => $ssoSpId,
            ]);
        $graph->shouldReceive('ensureApplicationUserRole')
            ->once()
            ->with($tenantId, $scimObjectId, $scimAppId)
            ->andReturn($roleId);
        $graph->shouldReceive('ensureApplicationUserRole')
            ->once()
            ->with($tenantId, $ssoObjectId, $ssoAppId)
            ->andReturn($roleId);
        $graph->shouldReceive('waitForServicePrincipalForAppId')
            ->with($tenantId, $scimAppId, $scimSpId)
            ->andReturn($scimSpId);
        $graph->shouldReceive('waitForServicePrincipalForAppId')
            ->withArgs(fn (...$args) => ($args[0] ?? null) === $tenantId && ($args[1] ?? null) === $ssoAppId)
            ->andReturn($ssoSpId);
        $graph->shouldReceive('resolveAssignableAppRoleId')
            ->once()
            ->with($tenantId, $scimSpId, $scimAppId)
            ->andReturn($roleId);
        $graph->shouldReceive('resolveAssignableAppRoleId')
            ->once()
            ->with($tenantId, $ssoSpId, $ssoAppId)
            ->andReturn($roleId);
        $graph->shouldReceive('assignGroupToEnterpriseApp')->twice();

        $this->app->instance(MicrosoftGraphClient::class, $graph);

        $result = app(CustomerEntraBootstrapService::class)->bootstrap($client, $tenantId);

        $this->assertTrue($result['ok']);
        $client->refresh();
        $this->assertSame($tenantId, $client->entra_tenant_id);
        $this->assertSame(ClientOnboardingService::ENTRA_LICENSE_P1, $client->entra_license_tier);
        $this->assertSame($groupId, $client->entra_group_id);
        $this->assertSame($scimAppId, $client->entra_superops_app_id);
        $this->assertSame($ssoAppId, $client->entra_superops_sso_app_id);
        $this->assertTrue($client->onboarding_checklist['entra_group_created']);
        $this->assertTrue($client->onboarding_checklist['entra_admin_consent_granted']);
        $this->assertTrue($client->onboarding_checklist['superops_scim_app']);
        $this->assertTrue(
            collect($result['warnings'])->contains(
                fn (string $w): bool => str_contains($w, 'Still required: paste SuperOps SCIM'),
            ),
            'New tenant bootstrap should still prompt for SCIM token apply when checklist is incomplete.',
        );
    }

    public function test_bootstrap_omits_scim_prompt_when_already_complete(): void
    {
        $tenantId = 'd6017e9f-4aba-43f1-94c1-56d3b9051f6f';
        $client = Client::factory()->create([
            'name' => 'Already Done Co',
            'entra_tenant_id' => $tenantId,
            'entra_group_id' => '493a4f92-717a-435e-ae4a-466532745c32',
            'entra_license_tier' => 'p1',
            'entra_superops_app_id' => '11111111-1111-1111-1111-111111111111',
            'entra_superops_sso_app_id' => '22222222-2222-2222-2222-222222222222',
            'entra_synced_at' => now(),
            'onboarding_checklist' => [
                'entra_admin_consent_granted' => true,
                'entra_group_created' => true,
                'superops_scim_app' => true,
                'superops_scim_configured' => true,
                'superops_client_sso_configured' => true,
            ],
        ]);

        $graph = Mockery::mock(MicrosoftGraphClient::class);
        $graph->shouldReceive('isConfigured')->andReturn(true);
        $graph->shouldReceive('clearAccessTokenCache')->with($tenantId);
        $graph->shouldReceive('waitUntilAppOnlyGraphReady')
            ->once()
            ->andReturn(['ready' => true, 'attempts' => 1, 'last_error' => null]);
        $graph->shouldReceive('retryAfterConsentPropagation')
            ->times(4)
            ->andReturnUsing(function (string $tid, callable $op) {
                return $op();
            });
        $graph->shouldReceive('detectEntraDirectoryLicenseTier')->once()->andReturn('p1');
        $graph->shouldReceive('ensurePortalSecurityGroup')
            ->once()
            ->andReturn($client->entra_group_id);
        $graph->shouldReceive('ensureNamedEnterpriseApplication')
            ->twice()
            ->andReturn(
                [
                    'appId' => $client->entra_superops_app_id,
                    'applicationObjectId' => 'scim-obj',
                    'servicePrincipalId' => 'scim-sp',
                ],
                [
                    'appId' => $client->entra_superops_sso_app_id,
                    'applicationObjectId' => 'sso-obj',
                    'servicePrincipalId' => 'sso-sp',
                ],
            );
        $graph->shouldReceive('ensureApplicationUserRole')->twice()->andReturn('role');
        $graph->shouldReceive('waitForServicePrincipalForAppId')->twice()->andReturn('scim-sp', 'sso-sp');
        $graph->shouldReceive('resolveAssignableAppRoleId')->twice()->andReturn('role');
        $graph->shouldReceive('assignGroupToEnterpriseApp')->twice();

        $this->app->instance(MicrosoftGraphClient::class, $graph);

        $result = app(CustomerEntraBootstrapService::class)->bootstrap($client, $tenantId);

        $this->assertTrue($result['ok']);
        $combined = implode("\n", $result['warnings']);
        $this->assertStringNotContainsString('Still required: paste SuperOps SCIM', $combined);
        $this->assertStringNotContainsString('Client SSO SAML (step 08)', $combined);
    }

    public function test_group_failure_still_saves_p1_and_creates_apps(): void
    {
        $client = Client::factory()->create([
            'name' => 'YorPower',
            'entra_tenant_id' => null,
            'entra_group_id' => null,
            'entra_license_tier' => 'free',
        ]);

        $tenantId = '102598ee-d66c-4f69-a05d-80981b689d23';
        $scimAppId = 'aaaaaaaa-1111-1111-1111-111111111111';
        $ssoAppId = 'bbbbbbbb-2222-2222-2222-222222222222';

        $graph = Mockery::mock(MicrosoftGraphClient::class);
        $graph->shouldReceive('isConfigured')->andReturn(true);
        $graph->shouldReceive('clearAccessTokenCache')->with($tenantId);
        $graph->shouldReceive('waitUntilAppOnlyGraphReady')
            ->once()
            ->andReturn(['ready' => true, 'attempts' => 1, 'last_error' => null]);
        $graph->shouldReceive('retryAfterConsentPropagation')
            ->times(4)
            ->andReturnUsing(function (string $tid, callable $op) {
                return $op();
            });
        $graph->shouldReceive('detectEntraDirectoryLicenseTier')->once()->andReturn('p1');
        $graph->shouldReceive('ensurePortalSecurityGroup')
            ->once()
            ->andThrow(new \RuntimeException('Microsoft Graph cannot create security groups (HTTP 403)'));
        $graph->shouldReceive('ensureNamedEnterpriseApplication')
            ->once()
            ->with($tenantId, 'SuperOps - YorPower')
            ->andReturn([
                'appId' => $scimAppId,
                'applicationObjectId' => 'scim-obj',
                'servicePrincipalId' => 'scim-sp',
            ]);
        $graph->shouldReceive('ensureNamedEnterpriseApplication')
            ->once()
            ->with($tenantId, 'SuperOps Requester SSO - YorPower')
            ->andReturn([
                'appId' => $ssoAppId,
                'applicationObjectId' => 'sso-obj',
                'servicePrincipalId' => 'sso-sp',
            ]);
        $graph->shouldReceive('ensureApplicationUserRole')->twice()->andReturn('role');
        $graph->shouldNotReceive('assignGroupToEnterpriseApp');

        $this->app->instance(MicrosoftGraphClient::class, $graph);

        $result = app(CustomerEntraBootstrapService::class)->bootstrap($client, $tenantId);

        $this->assertFalse($result['ok']);
        $client->refresh();
        $this->assertSame($tenantId, $client->entra_tenant_id);
        $this->assertSame(ClientOnboardingService::ENTRA_LICENSE_P1, $client->entra_license_tier);
        $this->assertNull($client->entra_group_id);
        $this->assertSame($scimAppId, $client->entra_superops_app_id);
        $this->assertSame($ssoAppId, $client->entra_superops_sso_app_id);
        $this->assertTrue($client->onboarding_checklist['entra_admin_consent_granted'] ?? false);
        $this->assertTrue($client->onboarding_checklist['superops_scim_app'] ?? false);
        $this->assertStringContainsString('group still missing', strtolower($result['summary']));
    }
}
