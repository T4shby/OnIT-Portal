<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Client;
use App\Models\User;
use App\Services\Dropsuite\DropsuiteClientMetricsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DropsuiteOrganizationImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.dropsuite.enabled' => true,
            'services.dropsuite.api_url' => 'https://dropsuite.uk/api',
            'services.dropsuite.reseller_token' => 'reseller-test-token',
            'services.dropsuite.auth_token' => 'auth-test-token',
        ]);
    }

    public function test_list_reseller_organizations_omits_tokens_and_groups_users(): void
    {
        $this->fakeUsers();

        $orgs = app(DropsuiteClientMetricsService::class)->listResellerOrganizations();

        $this->assertCount(2, $orgs);
        $yor = collect($orgs)->firstWhere('organization_id', '6182');
        $this->assertNotNull($yor);
        $this->assertSame('YorPower', $yor['name']);
        $this->assertSame(2, $yor['user_count']);
        $this->assertSame(['organization_id', 'name', 'user_count'], array_keys($yor));
        $this->assertContains('9999', array_column($orgs, 'organization_id'));
    }

    public function test_staff_import_page_filters_to_live_clients_and_hides_tokens(): void
    {
        $this->fakeUsers();
        $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
        Client::factory()->create(['name' => 'YorPower', 'is_active' => true]);
        Client::factory()->create(['name' => 'Ghost Ltd', 'is_active' => true]);

        $this->actingAs($admin)
            ->get(route('admin.clients.dropsuite-organizations'))
            ->assertOk()
            ->assertSee('YorPower')
            ->assertSee('6182')
            ->assertDontSee('SECRET-USER-TOKEN')
            ->assertDontSee('Parked Org');
    }

    public function test_staff_can_assign_organization_to_live_client(): void
    {
        $this->fakeUsers();
        Http::fake([
            'https://dropsuite.uk/api/users*' => Http::response([
                'result_set' => [
                    [
                        'organization_id' => 6182,
                        'organization_name' => 'YorPower',
                        'authentication_token' => 'SECRET-USER-TOKEN',
                        'admin' => true,
                    ],
                ],
                'pagination' => ['current_page' => 1, 'total_pages' => 1],
            ], 200),
            '*' => Http::response(['result_set' => []], 200),
        ]);

        $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
        $client = Client::factory()->create(['name' => 'YorPower', 'dropsuite_organization_id' => null]);

        $this->actingAs($admin)
            ->post(route('admin.clients.dropsuite-organizations.assign'), [
                'organization_id' => '6182',
                'client_id' => $client->id,
            ])
            ->assertRedirect();

        $this->assertSame('6182', $client->fresh()->dropsuite_organization_id);
    }

    public function test_hyphenated_user_id_is_rejected(): void
    {
        $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
        $client = Client::factory()->create();

        $this->actingAs($admin)
            ->from(route('admin.clients.dropsuite-organizations'))
            ->post(route('admin.clients.dropsuite-organizations.assign'), [
                'organization_id' => '360787-12',
                'client_id' => $client->id,
            ])
            ->assertRedirect(route('admin.clients.dropsuite-organizations'))
            ->assertSessionHas('error');

        $this->assertNull($client->fresh()->dropsuite_organization_id);
    }

    public function test_account_manager_cannot_map_unassigned_client(): void
    {
        $mine = Client::factory()->create(['name' => 'Mine']);
        $theirs = Client::factory()->create(['name' => 'Theirs']);
        $manager = User::factory()->create(['role' => UserRole::AccountManager, 'client_id' => null]);
        $manager->assignedClients()->attach($mine);

        $this->actingAs($manager)
            ->post(route('admin.clients.dropsuite-organizations.assign'), [
                'organization_id' => '6182',
                'client_id' => $theirs->id,
            ])
            ->assertForbidden();

        $this->assertNull($theirs->fresh()->dropsuite_organization_id);
    }

    public function test_client_admin_cannot_open_import_page(): void
    {
        $client = Client::factory()->create();
        $user = User::factory()->create([
            'role' => UserRole::ClientAdmin,
            'client_id' => $client->id,
        ]);

        $this->actingAs($user)
            ->get(route('admin.clients.dropsuite-organizations'))
            ->assertForbidden();
    }

    private function fakeUsers(): void
    {
        Http::fake([
            'https://dropsuite.uk/api/users*' => Http::response([
                'result_set' => [
                    [
                        'id' => '177210-12',
                        'organization_id' => 6182,
                        'organization_name' => 'YorPower',
                        'authentication_token' => 'SECRET-USER-TOKEN',
                        'admin' => true,
                    ],
                    [
                        'id' => '177211-12',
                        'organization_id' => 6182,
                        'organization_name' => 'YorPower',
                        'authentication_token' => 'OTHER-SECRET',
                        'admin' => false,
                    ],
                    [
                        'id' => '1-12',
                        'organization_id' => 9999,
                        'organization_name' => 'Parked Org',
                        'authentication_token' => 'PARKED-SECRET',
                        'admin' => true,
                    ],
                ],
                'pagination' => ['current_page' => 1, 'total_pages' => 1],
            ], 200),
        ]);
    }
}
