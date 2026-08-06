<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ClientProductEntitlementsTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_admin_hides_not_sold_products(): void
    {
        $client = Client::factory()->create([
            'superops_account_id' => null,
            'huntress_organization_id' => null,
            'dropsuite_organization_id' => null,
            'entra_tenant_id' => null,
            'product_entitlements' => [
                'superops' => ['entitled' => false],
                'huntress' => ['entitled' => false],
                'dropsuite' => ['entitled' => false],
                'm365' => ['entitled' => false],
            ],
        ]);
        $admin = User::factory()->create([
            'client_id' => $client->id,
            'role' => UserRole::ClientAdmin,
        ]);

        config([
            'services.superops.api_token' => 'token',
            'services.superops.subdomain' => 'onitltd',
            'services.huntress.enabled' => true,
            'services.huntress.api_key' => 'k',
            'services.huntress.api_secret' => 's',
            'services.dropsuite.enabled' => true,
        ]);

        $this->actingAs($admin)
            ->get(route('client-admin.dashboard'))
            ->assertOk()
            ->assertDontSee('Security (Huntress)')
            ->assertDontSee('Backups (Dropsuite)')
            ->assertDontSee('Managed devices')
            ->assertDontSee('Please contact your account manager to get this sorted');
    }

    public function test_client_admin_sees_contact_am_when_entitled_not_mapped(): void
    {
        $client = Client::factory()->create([
            'superops_account_id' => null,
            'huntress_organization_id' => null,
            'product_entitlements' => [
                'superops' => ['entitled' => true],
                'huntress' => ['entitled' => true],
            ],
        ]);
        $admin = User::factory()->create([
            'client_id' => $client->id,
            'role' => UserRole::ClientAdmin,
        ]);

        config([
            'services.superops.api_token' => 'token',
            'services.superops.subdomain' => 'onitltd',
            'services.huntress.enabled' => true,
            'services.huntress.api_key' => 'k',
            'services.huntress.api_secret' => 's',
        ]);

        $this->actingAs($admin)
            ->get(route('client-admin.dashboard'))
            ->assertOk()
            ->assertSee('Please contact your account manager to get this sorted')
            ->assertSee('Managed devices')
            ->assertSee('Security (Huntress)');
    }

    public function test_requester_hides_entitled_but_unmapped(): void
    {
        $client = Client::factory()->create([
            'superops_account_id' => null,
            'huntress_organization_id' => null,
            'product_entitlements' => [
                'superops' => ['entitled' => true],
                'huntress' => ['entitled' => true],
            ],
        ]);
        $requester = User::factory()->create([
            'client_id' => $client->id,
            'role' => UserRole::ClientRequester,
        ]);

        config([
            'services.superops.api_token' => 'token',
            'services.superops.subdomain' => 'onitltd',
            'services.huntress.enabled' => true,
            'services.huntress.api_key' => 'k',
            'services.huntress.api_secret' => 's',
        ]);

        $this->actingAs($requester)
            ->get(route('client-admin.dashboard'))
            ->assertOk()
            ->assertDontSee('Security (Huntress)')
            ->assertDontSee('Managed devices')
            ->assertDontSee('Please contact your account manager to get this sorted');
    }

    public function test_admin_can_save_product_entitlements(): void
    {
        $staff = User::factory()->create(['role' => UserRole::SuperAdmin]);
        $client = Client::factory()->create([
            'product_entitlements' => null,
        ]);

        $this->actingAs($staff)
            ->put(route('admin.clients.update', $client), [
                'name' => $client->name,
                'products' => [
                    'superops' => '1',
                    'm365' => '0',
                    'huntress' => '1',
                    'dropsuite' => '0',
                    'pax8' => '0',
                ],
                'superops_account_id' => null,
                'huntress_organization_id' => null,
                'is_active' => '1',
                'entra_license_tier' => 'free',
            ])
            ->assertRedirect(route('admin.clients.edit', $client));

        $client->refresh();
        $this->assertTrue((bool) ($client->product_entitlements['superops']['entitled'] ?? false));
        $this->assertTrue((bool) ($client->product_entitlements['huntress']['entitled'] ?? false));
        $this->assertFalse((bool) ($client->product_entitlements['dropsuite']['entitled'] ?? true));
    }

    public function test_clients_index_shows_product_chips(): void
    {
        $staff = User::factory()->create(['role' => UserRole::SuperAdmin]);
        Client::factory()->create([
            'name' => 'Chipco Ltd',
            'product_entitlements' => [
                'superops' => ['entitled' => true],
            ],
            'superops_account_id' => 'abc',
        ]);

        config(['services.superops.api_token' => 't', 'services.superops.subdomain' => 'x']);

        $this->actingAs($staff)
            ->get(route('admin.clients.index'))
            ->assertOk()
            ->assertSee('Products')
            ->assertSee('Chipco Ltd');
    }
}
