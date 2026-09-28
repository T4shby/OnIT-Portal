<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Jobs\ApplySuperOpsScimJob;
use App\Jobs\SyncEntraClientJob;
use App\Models\Client;
use App\Models\User;
use App\Services\EntraSync\MicrosoftGraphClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class ApplySuperOpsScimTest extends TestCase
{
    use RefreshDatabase;

    public function test_apply_queues_background_job_immediately(): void
    {
        Queue::fake();
        config(['services.entra_sync.enabled' => true]);

        $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
        $client = Client::factory()->create([
            'name' => 'Find',
            'entra_tenant_id' => 'd6017e9f-4aba-43f1-94c1-56d3b9051f6f',
            'entra_group_id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            'entra_superops_app_id' => '18c39a86-3475-40cd-afb5-c0525f4b2049',
            'onboarding_checklist' => [],
        ]);

        $response = $this->actingAs($admin)->post(route('admin.clients.apply-scim', $client), [
            'scim_tenant_url' => 'https://usserv.superops.ai/accounts-web/scim/example',
            'scim_secret_token' => 'scim-secret-token-example',
        ]);

        $response->assertRedirect(route('admin.clients.edit', $client));
        $response->assertSessionHas('success');
        $this->assertTrue(Cache::has(ApplySuperOpsScimJob::IN_FLIGHT_KEY_PREFIX.$client->id));
        Queue::assertPushed(ApplySuperOpsScimJob::class, function (ApplySuperOpsScimJob $job) use ($client) {
            return $job->clientId === $client->id
                && $job->scimTenantUrl === 'https://usserv.superops.ai/accounts-web/scim/example'
                && $job->scimSecretToken === 'scim-secret-token-example';
        });
        // Graph work is off-request; checklist is untouched until the worker finishes.
        $client->refresh();
        $this->assertFalse($client->onboarding_checklist['superops_scim_provisioning'] ?? false);
    }

    public function test_job_complete_only_when_mappings_and_sync_queued(): void
    {
        Queue::fake();
        config(['services.entra_sync.enabled' => true]);

        $client = Client::factory()->create([
            'name' => 'Find',
            'entra_tenant_id' => 'd6017e9f-4aba-43f1-94c1-56d3b9051f6f',
            'entra_group_id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            'entra_superops_app_id' => '18c39a86-3475-40cd-afb5-c0525f4b2049',
            'onboarding_checklist' => [],
        ]);

        $graph = Mockery::mock(MicrosoftGraphClient::class);
        $graph->shouldReceive('applySuperOpsScimCredentials')
            ->once()
            ->andReturn([
                'jobId' => 'job-1',
                'servicePrincipalId' => 'sp-1',
                'started' => true,
                'nameMappingsConfigured' => true,
                'details' => ['SCIM BaseAddress + SecretToken written to Entra'],
                'warnings' => [],
            ]);
        $graph->shouldReceive('getSuperOpsScimProvisioningHealth')
            ->once()
            ->andReturn(['ok' => true]);
        $this->app->instance(MicrosoftGraphClient::class, $graph);

        ApplySuperOpsScimJob::markQueued($client->id);
        (new ApplySuperOpsScimJob(
            $client->id,
            'https://usserv.superops.ai/accounts-web/scim/example',
            'scim-secret-token-example',
        ))->handle(
            app(MicrosoftGraphClient::class),
            app(\App\Services\ClientOnboardingService::class),
            app(\App\Services\ActivityLogService::class),
        );

        $client->refresh();
        $this->assertTrue($client->onboarding_checklist['superops_scim_tokens'] ?? false);
        $this->assertTrue($client->onboarding_checklist['superops_scim_name_mappings'] ?? false);
        $this->assertTrue($client->onboarding_checklist['superops_scim_sync_queued'] ?? false);
        $this->assertTrue($client->onboarding_checklist['superops_scim_provisioning'] ?? false);
        $this->assertFalse(Cache::has(ApplySuperOpsScimJob::IN_FLIGHT_KEY_PREFIX.$client->id));
        $result = Cache::get(ApplySuperOpsScimJob::LAST_RESULT_KEY_PREFIX.$client->id);
        $this->assertTrue($result['step_complete'] ?? false);
        Queue::assertPushed(SyncEntraClientJob::class);
    }

    public function test_job_without_group_does_not_complete_step_07(): void
    {
        Queue::fake();
        config(['services.entra_sync.enabled' => true]);

        $client = Client::factory()->create([
            'name' => 'YorPower',
            'entra_tenant_id' => 'd6017e9f-4aba-43f1-94c1-56d3b9051f6f',
            'entra_group_id' => null,
            'entra_superops_app_id' => '18c39a86-3475-40cd-afb5-c0525f4b2049',
            'onboarding_checklist' => [],
        ]);

        $graph = Mockery::mock(MicrosoftGraphClient::class);
        $graph->shouldReceive('applySuperOpsScimCredentials')
            ->once()
            ->andReturn([
                'jobId' => 'job-1',
                'servicePrincipalId' => 'sp-1',
                'started' => true,
                'nameMappingsConfigured' => true,
                'details' => [],
                'warnings' => [],
            ]);
        $this->app->instance(MicrosoftGraphClient::class, $graph);

        (new ApplySuperOpsScimJob(
            $client->id,
            'https://usserv.superops.ai/accounts-web/scim/example',
            'scim-secret-token-example',
        ))->handle(
            app(MicrosoftGraphClient::class),
            app(\App\Services\ClientOnboardingService::class),
            app(\App\Services\ActivityLogService::class),
        );

        $client->refresh();
        $this->assertTrue($client->onboarding_checklist['superops_scim_tokens'] ?? false);
        $this->assertTrue($client->onboarding_checklist['superops_scim_name_mappings'] ?? false);
        $this->assertFalse($client->onboarding_checklist['superops_scim_sync_queued'] ?? true);
        $this->assertFalse($client->onboarding_checklist['superops_scim_provisioning'] ?? true);
        Queue::assertNotPushed(SyncEntraClientJob::class);
    }

    public function test_job_without_name_mappings_does_not_complete_step_07(): void
    {
        Queue::fake();
        config(['services.entra_sync.enabled' => true]);

        $client = Client::factory()->create([
            'entra_tenant_id' => 'd6017e9f-4aba-43f1-94c1-56d3b9051f6f',
            'entra_group_id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            'entra_superops_app_id' => '18c39a86-3475-40cd-afb5-c0525f4b2049',
            'onboarding_checklist' => [],
        ]);

        $graph = Mockery::mock(MicrosoftGraphClient::class);
        $graph->shouldReceive('applySuperOpsScimCredentials')
            ->once()
            ->andReturn([
                'jobId' => 'job-1',
                'servicePrincipalId' => 'sp-1',
                'started' => true,
                'nameMappingsConfigured' => false,
                'details' => [],
                'warnings' => ['Name attribute mapping not auto-applied after wait'],
            ]);
        $this->app->instance(MicrosoftGraphClient::class, $graph);

        (new ApplySuperOpsScimJob(
            $client->id,
            'https://usserv.superops.ai/accounts-web/scim/example',
            'scim-secret-token-example',
        ))->handle(
            app(MicrosoftGraphClient::class),
            app(\App\Services\ClientOnboardingService::class),
            app(\App\Services\ActivityLogService::class),
        );

        $client->refresh();
        $this->assertFalse($client->onboarding_checklist['superops_scim_name_mappings'] ?? true);
        $this->assertTrue($client->onboarding_checklist['superops_scim_sync_queued'] ?? false);
        $this->assertFalse($client->onboarding_checklist['superops_scim_provisioning'] ?? true);
    }
}
