<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Client;
use App\Models\User;
use App\Services\EntraSync\MicrosoftGraphClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class ApplyClientSsoSamlTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_configure_client_sso_saml(): void
    {
        $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
        $client = Client::factory()->create([
            'name' => 'Find',
            'entra_tenant_id' => 'd6017e9f-4aba-43f1-94c1-56d3b9051f6f',
            'entra_superops_sso_app_id' => '618d075e-9f34-4a20-ae62-333ec8c8dc43',
            'onboarding_checklist' => [],
        ]);

        $graph = Mockery::mock(MicrosoftGraphClient::class);
        $graph->shouldReceive('applyClientSsoSamlConfiguration')
            ->once()
            ->andReturn([
                'loginUrl' => 'https://login.microsoftonline.com/d6017e9f-4aba-43f1-94c1-56d3b9051f6f/saml2',
                'certificateBase64' => 'MIID...test',
                'azureAdIdentifier' => 'https://sts.windows.net/d6017e9f-4aba-43f1-94c1-56d3b9051f6f/',
                'servicePrincipalId' => 'sp-1',
                'applicationObjectId' => 'app-1',
                'details' => ['preferredSingleSignOnMode=saml'],
                'warnings' => [],
            ]);
        $this->app->instance(MicrosoftGraphClient::class, $graph);

        $response = $this->actingAs($admin)->post(route('admin.clients.apply-client-sso', $client), [
            'entity_id' => 'https://clientuser.superops.ai/saml/123/meta',
            'consumer_service_url' => 'https://portal.onit.ltd/accounts-web/accounts/saml/response/456',
        ]);

        $response->assertRedirect(route('admin.clients.edit', $client));
        $response->assertSessionHas('client_sso_login_url');
        $response->assertSessionHas('client_sso_certificate');

        $client->refresh();
        $this->assertTrue($client->onboarding_checklist['superops_client_sso_configured'] ?? false);
    }
}
