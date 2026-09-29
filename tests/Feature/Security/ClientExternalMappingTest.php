<?php

namespace Tests\Feature\Security;

use App\Enums\UserRole;
use App\Jobs\BootstrapClientEntraJob;
use App\Models\Client;
use App\Models\User;
use App\Support\AdminConsentState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * A client's external ids (SuperOps account, Huntress / Dropsuite org, Pax8 company,
 * Entra tenant) decide whose tickets, devices, incidents, backups and directory the
 * portal shows for it. Pointing an assigned client at another client's id let an
 * account manager read an unassigned tenant's data, and a copy-paste slip showed one
 * customer another customer's data.
 */
class ClientExternalMappingTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = '11111111-2222-3333-4444-555555555555';

    /**
     * @return array<string, array{string, string}>
     */
    public static function mappingFields(): array
    {
        return [
            'superops account' => ['superops_account_id', '3425667307281944576'],
            'huntress org' => ['huntress_organization_id', '4242'],
            'dropsuite org' => ['dropsuite_organization_id', '6182'],
            'pax8 company' => ['pax8_company_id', 'pax8-co-1'],
            'entra tenant' => ['entra_tenant_id', self::TENANT],
        ];
    }

    #[DataProvider('mappingFields')]
    public function test_account_manager_cannot_point_assigned_client_at_another_clients_account(string $field, string $value): void
    {
        $foreign = Client::factory()->create([$field => $value]);
        $mine = Client::factory()->create();
        $manager = User::factory()->create(['role' => UserRole::AccountManager, 'client_id' => null]);
        $manager->assignedClients()->attach($mine);

        $this->actingAs($manager)
            ->from(route('admin.clients.edit', $mine))
            ->put(route('admin.clients.update', $mine), [
                'name' => $mine->name,
                'is_active' => '1',
                $field => strtoupper($value),
            ])
            ->assertSessionHasErrors($field);

        $this->assertNull($mine->fresh()->{$field});
        $this->assertSame($value, $foreign->fresh()->{$field});
    }

    public function test_super_admin_cannot_create_a_second_client_on_the_same_superops_account(): void
    {
        Client::factory()->create(['superops_account_id' => 'acc-1']);
        $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);

        $this->actingAs($admin)
            ->post(route('admin.clients.store'), ['name' => 'Copy Paste Ltd', 'superops_account_id' => 'acc-1', 'is_active' => '1'])
            ->assertSessionHasErrors('superops_account_id');

        $this->assertNull(Client::where('name', 'Copy Paste Ltd')->first());
    }

    public function test_unchanged_pre_existing_duplicate_does_not_block_other_edits(): void
    {
        Client::factory()->create(['superops_account_id' => 'acc-shared']);
        $client = Client::factory()->create(['superops_account_id' => 'acc-shared']);
        $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);

        $this->actingAs($admin)
            ->put(route('admin.clients.update', $client), [
                'name' => 'Renamed Ltd',
                'superops_account_id' => 'acc-shared',
                'is_active' => '1',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('Renamed Ltd', $client->fresh()->name);
    }

    public function test_keeping_own_value_and_setting_a_fresh_one_are_allowed(): void
    {
        $client = Client::factory()->create(['superops_account_id' => 'acc-own']);
        $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);

        $this->actingAs($admin)
            ->put(route('admin.clients.update', $client), [
                'name' => $client->name,
                'superops_account_id' => 'acc-own',
                'huntress_organization_id' => '99',
                'is_active' => '1',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('99', $client->fresh()->huntress_organization_id);
    }

    public function test_admin_consent_does_not_link_a_tenant_already_linked_to_another_client(): void
    {
        Bus::fake();
        $owner = Client::factory()->create(['entra_tenant_id' => self::TENANT]);
        $unlinked = Client::factory()->create(['entra_tenant_id' => null]);

        $this->get(route('auth.microsoft.callback', [
            'admin_consent' => 'True',
            'state' => AdminConsentState::encode($unlinked->id),
            'tenant' => self::TENANT,
        ]))->assertOk()->assertSee('already linked to another client', false);

        $this->assertNull($unlinked->fresh()->entra_tenant_id);
        $this->assertSame(self::TENANT, $owner->fresh()->entra_tenant_id);
        Bus::assertNotDispatched(BootstrapClientEntraJob::class);
    }
}
