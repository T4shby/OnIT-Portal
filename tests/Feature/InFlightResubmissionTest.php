<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Jobs\ApplyClientSsoSamlJob;
use App\Jobs\ApplySuperOpsScimJob;
use App\Jobs\BootstrapClientEntraJob;
use App\Jobs\RepairSuperOpsScimExportJob;
use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Pass 7 L9: re-submitting a slow per-client admin action while the previous run is
 * still pending/running used to be dropped silently by ShouldBeUnique - the new
 * form values were lost while the page still said "running in the background".
 * The second submit must now be refused visibly, and nothing new may be queued.
 */
class InFlightResubmissionTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::SuperAdmin]);
    }

    private function linkedClient(): Client
    {
        return Client::factory()->create([
            'entra_tenant_id' => 'd6017e9f-4aba-43f1-94c1-56d3b9051f6f',
            'entra_group_id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            'entra_superops_app_id' => '18c39a86-3475-40cd-afb5-c0525f4b2049',
            'entra_superops_sso_app_id' => '618d075e-9f34-4a20-ae62-333ec8c8dc43',
            'onboarding_checklist' => [],
        ]);
    }

    public function test_second_client_sso_submit_while_first_is_running_is_refused_not_dropped(): void
    {
        Queue::fake();
        $admin = $this->admin();
        $client = $this->linkedClient();

        $this->actingAs($admin)->post(route('admin.clients.apply-client-sso', $client), [
            'entity_id' => 'https://clientuser.superops.ai/saml/FIRST/meta',
            'consumer_service_url' => 'https://portal.onit.ltd/accounts-web/accounts/saml/response/1',
        ])->assertSessionHas('success');

        $second = $this->actingAs($admin)->post(route('admin.clients.apply-client-sso', $client), [
            'entity_id' => 'https://clientuser.superops.ai/saml/SECOND/meta',
            'consumer_service_url' => 'https://portal.onit.ltd/accounts-web/accounts/saml/response/2',
        ]);

        $second->assertRedirect(route('admin.clients.edit', $client));
        $second->assertSessionMissing('success');
        $second->assertSessionHas('error', fn (string $m) => str_contains($m, 'already running')
            && str_contains($m, 'NOT applied'));
        // The typed values come back into the form rather than vanishing.
        $second->assertSessionHasInput('entity_id', 'https://clientuser.superops.ai/saml/SECOND/meta');

        Queue::assertPushed(ApplyClientSsoSamlJob::class, 1);
        Queue::assertPushed(ApplyClientSsoSamlJob::class, fn (ApplyClientSsoSamlJob $job) => str_contains($job->entityId, 'FIRST'));
    }

    public function test_second_scim_apply_while_first_is_running_is_refused_and_secret_not_flashed(): void
    {
        Queue::fake();
        config(['services.entra_sync.enabled' => true]);
        $admin = $this->admin();
        $client = $this->linkedClient();

        $this->actingAs($admin)->post(route('admin.clients.apply-scim', $client), [
            'scim_tenant_url' => 'https://usserv.superops.ai/accounts-web/scim/first',
            'scim_secret_token' => 'first-secret-token',
        ])->assertSessionHas('success');

        $second = $this->actingAs($admin)->post(route('admin.clients.apply-scim', $client), [
            'scim_tenant_url' => 'https://usserv.superops.ai/accounts-web/scim/second',
            'scim_secret_token' => 'second-secret-token',
        ]);

        $second->assertSessionMissing('success');
        $second->assertSessionHas('error', fn (string $m) => str_contains($m, 'NOT applied'));
        $second->assertSessionHasInput('scim_tenant_url', 'https://usserv.superops.ai/accounts-web/scim/second');
        $this->assertArrayNotHasKey('scim_secret_token', session()->getOldInput());

        Queue::assertPushed(ApplySuperOpsScimJob::class, 1);
        Queue::assertPushed(ApplySuperOpsScimJob::class, fn (ApplySuperOpsScimJob $job) => $job->scimSecretToken === 'first-secret-token');
    }

    public function test_retry_scim_export_is_refused_while_apply_or_an_earlier_retry_is_running(): void
    {
        Queue::fake();
        $admin = $this->admin();
        $client = $this->linkedClient();

        ApplySuperOpsScimJob::markQueued($client->id);
        $this->actingAs($admin)->post(route('admin.clients.retry-scim-export', $client))
            ->assertSessionHas('error', fn (string $m) => str_contains($m, 'already running'));
        Queue::assertNotPushed(RepairSuperOpsScimExportJob::class);
        $this->assertFalse(RepairSuperOpsScimExportJob::isInFlight($client->id));

        Cache::forget(ApplySuperOpsScimJob::IN_FLIGHT_KEY_PREFIX.$client->id);
        $this->actingAs($admin)->post(route('admin.clients.retry-scim-export', $client))
            ->assertSessionHas('success');
        $this->actingAs($admin)->post(route('admin.clients.retry-scim-export', $client))
            ->assertSessionHas('error', fn (string $m) => str_contains($m, 'already running'));
        Queue::assertPushed(RepairSuperOpsScimExportJob::class, 1);
    }

    public function test_bootstrap_is_refused_while_running_and_the_button_is_disabled(): void
    {
        Queue::fake();
        config([
            'services.entra_sync.client_id' => 'portal-app-id',
            'services.entra_sync.client_secret' => 'secret',
        ]);
        $admin = $this->admin();
        $client = $this->linkedClient();

        $this->actingAs($admin)->post(route('admin.clients.bootstrap-entra', $client))->assertSessionHas('success');
        $this->actingAs($admin)->post(route('admin.clients.bootstrap-entra', $client))
            ->assertSessionHas('error', fn (string $m) => str_contains($m, 'already running'));
        Queue::assertPushed(BootstrapClientEntraJob::class, 1);

        $this->actingAs($admin)->get(route('admin.clients.edit', $client))
            ->assertOk()
            ->assertSee('Graph setup running');
    }

    public function test_a_new_submit_is_accepted_once_the_previous_run_has_finished(): void
    {
        Queue::fake();
        $admin = $this->admin();
        $client = $this->linkedClient();

        $this->assertTrue(ApplyClientSsoSamlJob::claim($client->id));
        $this->assertFalse(ApplyClientSsoSamlJob::claim($client->id));

        // What the job's finish() does on success, failure and failed().
        Cache::forget(ApplyClientSsoSamlJob::IN_FLIGHT_KEY_PREFIX.$client->id);

        $this->actingAs($admin)->post(route('admin.clients.apply-client-sso', $client), [
            'entity_id' => 'https://clientuser.superops.ai/saml/NEW/meta',
            'consumer_service_url' => 'https://portal.onit.ltd/accounts-web/accounts/saml/response/3',
        ])->assertSessionHas('success');

        Queue::assertPushed(ApplyClientSsoSamlJob::class, fn (ApplyClientSsoSamlJob $job) => str_contains($job->entityId, 'NEW'));
    }
}
