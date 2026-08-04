<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Client;
use App\Models\User;
use App\Services\EntraSync\MicrosoftGraphClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class ApplySuperOpsScimTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_apply_scim_credentials_and_mark_checklist(): void
    {
        $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
        $client = Client::factory()->create([
            'name' => 'Find',
            'entra_tenant_id' => 'd6017e9f-4aba-43f1-94c1-56d3b9051f6f',
            'entra_superops_app_id' => '18c39a86-3475-40cd-afb5-c0525f4b2049',
            'onboarding_checklist' => [],
        ]);

        $graph = Mockery::mock(MicrosoftGraphClient::class);
        $graph->shouldReceive('applySuperOpsScimCredentials')
            ->once()
            ->with(
                $client->entra_tenant_id,
                $client->entra_superops_app_id,
                'https://usserv.superops.ai/accounts-web/scim/example',
                'scim-secret-token-example',
            )
            ->andReturn([
                'jobId' => 'job-1',
                'servicePrincipalId' => 'sp-1',
                'started' => true,
                'nameMappingsConfigured' => true,
                'details' => ['SCIM BaseAddress + SecretToken written to Entra'],
                'warnings' => [],
            ]);
        $this->app->instance(MicrosoftGraphClient::class, $graph);

        $response = $this->actingAs($admin)->post(route('admin.clients.apply-scim', $client), [
            'scim_tenant_url' => 'https://usserv.superops.ai/accounts-web/scim/example',
            'scim_secret_token' => 'scim-secret-token-example',
        ]);

        $response->assertRedirect(route('admin.clients.edit', $client));
        $response->assertSessionHas('success');

        $client->refresh();
        $this->assertTrue($client->onboarding_checklist['superops_scim_tokens'] ?? false);
        $this->assertTrue($client->onboarding_checklist['superops_scim_provisioning'] ?? false);
    }
}
