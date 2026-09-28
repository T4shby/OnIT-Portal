<?php

namespace Tests\Feature\Security;

use App\Models\Client;
use App\Services\EntraSync\CustomerEntraBootstrapService;
use App\Support\AdminConsentState;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_non_guid_tenant_is_never_passed_to_bootstrap(): void
    {
        $client = Client::factory()->create(['entra_tenant_id' => null]);

        $bootstrap = Mockery::mock(CustomerEntraBootstrapService::class);
        $bootstrap->shouldReceive('bootstrap')
            ->once()
            ->withArgs(fn (Client $c, ?string $tenant) => $c->is($client) && $tenant === null)
            ->andReturn(['ok' => false, 'summary' => 'No tenant ID from Accept or client record.', 'details' => [], 'warnings' => []]);
        $this->app->instance(CustomerEntraBootstrapService::class, $bootstrap);

        $this->get($this->consentUrl([
            'state' => AdminConsentState::encode($client->id),
            'tenant' => '../../evil',
        ]))->assertOk();

        $this->assertNull($client->fresh()->entra_tenant_id);
    }

    public function test_signed_state_with_matching_tenant_still_bootstraps(): void
    {
        $client = Client::factory()->create(['entra_tenant_id' => self::LINKED_TENANT]);

        $bootstrap = Mockery::mock(CustomerEntraBootstrapService::class);
        $bootstrap->shouldReceive('bootstrap')
            ->once()
            ->withArgs(fn (Client $c, ?string $tenant) => $c->is($client) && $tenant === self::LINKED_TENANT)
            ->andReturn(['ok' => true, 'summary' => 'Microsoft tenant connected.', 'details' => [], 'warnings' => []]);
        $this->app->instance(CustomerEntraBootstrapService::class, $bootstrap);

        $this->get($this->consentUrl([
            'state' => AdminConsentState::encode($client->id),
            'tenant' => strtoupper(self::LINKED_TENANT),
        ]))->assertOk();
    }
}
