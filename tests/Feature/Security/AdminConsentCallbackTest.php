<?php

namespace Tests\Feature\Security;

use App\Jobs\BootstrapClientEntraJob;
use App\Models\Client;
use App\Services\EntraSync\CustomerEntraBootstrapService;
use App\Support\AdminConsentState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

/**
 * The admin-consent return runs in the `guest` route group (staff Accept in a private
 * browser), so it is reachable without a portal session and must not let a crafted
 * URL change which Entra tenant a client is linked to.
 */
class AdminConsentCallbackTest extends TestCase
{
    use RefreshDatabase;

    private const LINKED_TENANT = '11111111-1111-1111-1111-111111111111';

    private const OTHER_TENANT = '99999999-9999-9999-9999-999999999999';

    private function consentUrl(array $query): string
    {
        return route('auth.microsoft.callback', array_merge(['admin_consent' => 'True'], $query));
    }

    private function expectNoBootstrap(): void
    {
        $bootstrap = Mockery::mock(CustomerEntraBootstrapService::class);
        $bootstrap->shouldNotReceive('bootstrap');
        $this->app->instance(CustomerEntraBootstrapService::class, $bootstrap);
    }

    public function test_unsigned_legacy_state_cannot_repoint_a_linked_client(): void
    {
        $this->expectNoBootstrap();
        $client = Client::factory()->create(['entra_tenant_id' => self::LINKED_TENANT]);

        $response = $this->get($this->consentUrl([
            'state' => 'client-'.$client->id,
            'tenant' => self::OTHER_TENANT,
        ]));

        $response->assertOk();
        $response->assertDontSee($client->name);
        $this->assertSame(self::LINKED_TENANT, $client->fresh()->entra_tenant_id);
    }

    public function test_signed_state_cannot_repoint_a_client_to_a_different_tenant(): void
    {
        $this->expectNoBootstrap();
        $client = Client::factory()->create(['entra_tenant_id' => self::LINKED_TENANT]);

        $response = $this->get($this->consentUrl([
            'state' => AdminConsentState::encode($client->id),
            'tenant' => self::OTHER_TENANT,
        ]));

        $response->assertOk();
        $response->assertSee('different Microsoft tenant', false);
        $response->assertDontSee($client->name);
        $this->assertSame(self::LINKED_TENANT, $client->fresh()->entra_tenant_id);
    }

    public function test_non_guid_tenant_is_never_persisted_and_nothing_is_queued(): void
    {
        Bus::fake();
        $this->expectNoBootstrap();
        $client = Client::factory()->create(['entra_tenant_id' => null]);

        $this->get($this->consentUrl([
            'state' => AdminConsentState::encode($client->id),
            'tenant' => '../../evil',
        ]))->assertOk()->assertSee('Run Connect Microsoft tenant first', false)->assertDontSee('../../evil', false);

        $this->assertNull($client->fresh()->entra_tenant_id);
        Bus::assertNotDispatched(BootstrapClientEntraJob::class);
    }

    public function test_signed_state_with_matching_tenant_queues_bootstrap_instead_of_running_inline(): void
    {
        Bus::fake();
        $this->expectNoBootstrap();
        $client = Client::factory()->create(['entra_tenant_id' => self::LINKED_TENANT]);

        $this->get($this->consentUrl([
            'state' => AdminConsentState::encode($client->id),
            'tenant' => strtoupper(self::LINKED_TENANT),
        ]))->assertOk()->assertSee('running in the background', false);

        Bus::assertDispatched(BootstrapClientEntraJob::class, fn ($job) => $job->clientId === $client->id);
        $this->assertTrue(Cache::has(BootstrapClientEntraJob::IN_FLIGHT_KEY_PREFIX.$client->id));
    }

    public function test_unlinked_client_gets_the_consent_tenant_and_a_queued_bootstrap(): void
    {
        Bus::fake();
        $this->expectNoBootstrap();
        $client = Client::factory()->create(['entra_tenant_id' => null]);

        $this->get($this->consentUrl([
            'state' => AdminConsentState::encode($client->id),
            'tenant' => strtoupper(self::LINKED_TENANT),
        ]))->assertOk();

        $this->assertSame(self::LINKED_TENANT, $client->fresh()->entra_tenant_id);
        Bus::assertDispatched(BootstrapClientEntraJob::class, fn ($job) => $job->clientId === $client->id);
    }

    public function test_logged_in_staff_are_redirected_before_the_consent_handler(): void
    {
        // Documents why the old `Auth::check() && $client` branch was dead: the
        // route is in the guest group, so RedirectIfAuthenticated runs first.
        Bus::fake();
        $this->expectNoBootstrap();
        $admin = \App\Models\User::factory()->create(['role' => \App\Enums\UserRole::SuperAdmin]);
        $client = Client::factory()->create(['entra_tenant_id' => self::LINKED_TENANT]);

        $this->actingAs($admin)->get($this->consentUrl([
            'state' => AdminConsentState::encode($client->id),
            'tenant' => self::LINKED_TENANT,
        ]))->assertRedirect();

        Bus::assertNotDispatched(BootstrapClientEntraJob::class);
    }
}
