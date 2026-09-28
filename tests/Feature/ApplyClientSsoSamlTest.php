<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Jobs\ApplyClientSsoSamlJob;
use App\Models\Client;
use App\Models\User;
use App\Services\EntraSync\MicrosoftGraphClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class ApplyClientSsoSamlTest extends TestCase
{
    use RefreshDatabase;

    public function test_apply_queues_background_job_immediately(): void
    {
        Queue::fake();

        $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
        $client = Client::factory()->create([
            'name' => 'Find',
            'entra_tenant_id' => 'd6017e9f-4aba-43f1-94c1-56d3b9051f6f',
            'entra_superops_sso_app_id' => '618d075e-9f34-4a20-ae62-333ec8c8dc43',
            'onboarding_checklist' => [],
        ]);

        $response = $this->actingAs($admin)->post(route('admin.clients.apply-client-sso', $client), [
            'entity_id' => 'https://clientuser.superops.ai/saml/123/meta',
            'consumer_service_url' => 'https://portal.onit.ltd/accounts-web/accounts/saml/response/456',
        ]);

        $response->assertRedirect(route('admin.clients.edit', $client));
        $response->assertSessionHas('success');
        $this->assertTrue(Cache::has(ApplyClientSsoSamlJob::IN_FLIGHT_KEY_PREFIX.$client->id));
        Queue::assertPushed(ApplyClientSsoSamlJob::class, function (ApplyClientSsoSamlJob $job) use ($client) {
            return $job->clientId === $client->id
                && $job->entityId === 'https://clientuser.superops.ai/saml/123/meta'
                && $job->consumerServiceUrl === 'https://portal.onit.ltd/accounts-web/accounts/saml/response/456';
        });
        // Graph work is off-request; checklist and the client_sso_idp cache are untouched
        // until the worker finishes.
        $client->refresh();
        $this->assertFalse($client->onboarding_checklist['superops_client_sso_configured'] ?? false);
        $this->assertNull(Cache::get('client_sso_idp.'.$client->id));
    }

    public function test_job_writes_login_url_and_certificate_to_cache_for_the_view(): void
    {
        Queue::fake();

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

        ApplyClientSsoSamlJob::markQueued($client->id);
        (new ApplyClientSsoSamlJob(
            $client->id,
            'https://clientuser.superops.ai/saml/123/meta',
            'https://portal.onit.ltd/accounts-web/accounts/saml/response/456',
        ))->handle(
            app(MicrosoftGraphClient::class),
            app(\App\Services\ClientOnboardingService::class),
            app(\App\Services\ActivityLogService::class),
        );

        $client->refresh();
        $this->assertTrue($client->onboarding_checklist['superops_client_sso_configured'] ?? false);
        $this->assertFalse(Cache::has(ApplyClientSsoSamlJob::IN_FLIGHT_KEY_PREFIX.$client->id));

        $idp = Cache::get('client_sso_idp.'.$client->id);
        $this->assertSame('https://login.microsoftonline.com/d6017e9f-4aba-43f1-94c1-56d3b9051f6f/saml2', $idp['loginUrl']);
        $this->assertSame('MIID...test', $idp['certificateBase64']);

        $result = Cache::get(ApplyClientSsoSamlJob::LAST_RESULT_KEY_PREFIX.$client->id);
        $this->assertTrue($result['success'] ?? false);
    }

    public function test_job_records_failure_without_touching_checklist(): void
    {
        Queue::fake();

        $client = Client::factory()->create([
            'entra_tenant_id' => 'd6017e9f-4aba-43f1-94c1-56d3b9051f6f',
            'entra_superops_sso_app_id' => '618d075e-9f34-4a20-ae62-333ec8c8dc43',
            'onboarding_checklist' => [],
        ]);

        $graph = Mockery::mock(MicrosoftGraphClient::class);
        $graph->shouldReceive('applyClientSsoSamlConfiguration')
            ->once()
            ->andThrow(new \RuntimeException('Microsoft Graph could not set Entity ID / Reply URL: 400 bad request'));
        $this->app->instance(MicrosoftGraphClient::class, $graph);

        ApplyClientSsoSamlJob::markQueued($client->id);
        (new ApplyClientSsoSamlJob(
            $client->id,
            'https://clientuser.superops.ai/saml/123/meta',
            'https://portal.onit.ltd/accounts-web/accounts/saml/response/456',
        ))->handle(
            app(MicrosoftGraphClient::class),
            app(\App\Services\ClientOnboardingService::class),
            app(\App\Services\ActivityLogService::class),
        );

        $client->refresh();
        $this->assertFalse($client->onboarding_checklist['superops_client_sso_configured'] ?? false);
        $this->assertFalse(Cache::has(ApplyClientSsoSamlJob::IN_FLIGHT_KEY_PREFIX.$client->id));

        $result = Cache::get(ApplyClientSsoSamlJob::LAST_RESULT_KEY_PREFIX.$client->id);
        $this->assertFalse($result['success'] ?? true);
        $this->assertStringContainsString('Entity ID / Reply URL', $result['message']);
    }
}
