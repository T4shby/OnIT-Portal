<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ClientRoleAccessTest extends TestCase
{
    use RefreshDatabase;

    private function clientWithEntra(): Client
    {
        config([
            'services.entra_sync.client_id' => 'test-client-id',
            'services.entra_sync.client_secret' => 'test-secret',
            'services.superops.api_token' => 'token',
            'services.superops.subdomain' => 'onitltd',
        ]);

        return Client::factory()->create([
            'entra_tenant_id' => '11111111-1111-1111-1111-111111111111',
            'superops_account_id' => '123456789',
            'superops_sso_enabled' => true,
        ]);
    }

    public function test_client_requester_can_access_dashboard_support_and_personal_org_views(): void
    {
        $client = $this->clientWithEntra();
        $user = User::factory()->create([
            'client_id' => $client->id,
            'role' => UserRole::ClientRequester,
            'email' => 'jane@example.test',
        ]);

        Cache::put("client:{$client->id}:superops-dashboard:v4", [
            'assets_total' => 50,
            'open_tickets_total' => 9,
            'open_tickets_table' => [
                [
                    'displayId' => '1',
                    'subject' => 'Jane ticket',
                    'priority' => 'High',
                    'status' => 'Open',
                    'createdTime' => now()->toIso8601String(),
                    'requesterEmail' => 'jane@example.test',
                    'requesterName' => 'Jane',
                    'requesterUserId' => '',
                ],
                [
                    'displayId' => '2',
                    'subject' => 'Bob ticket',
                    'priority' => 'Low',
                    'status' => 'Open',
                    'createdTime' => now()->toIso8601String(),
                    'requesterEmail' => 'bob@example.test',
                    'requesterName' => 'Bob',
                    'requesterUserId' => '',
                ],
            ],
            'tickets_created' => ['7' => 1, '14' => 2, '30' => 3, 'all' => 10],
            'tickets_closed' => ['7' => 1, '14' => 1, '30' => 2, 'all' => 8],
            'last_refreshed_at' => now()->toIso8601String(),
        ], now()->addHour());

        $this->actingAs($user)->get(route('dashboard'))->assertOk();
        $this->actingAs($user)->get(route('support.index'))->assertOk();
        $this->actingAs($user)->get(route('client-admin.dashboard'))
            ->assertOk()
            ->assertSee('Jane ticket')
            ->assertDontSee('Bob ticket')
            ->assertSeeText('only see items linked to you')
            ->assertSeeText('Support & Devices')
            ->assertDontSee('>Organisation</a>', false);
        $this->actingAs($user)->get(route('microsoft-365.directory'))->assertOk();
    }

    public function test_client_billing_admin_can_open_my_systems_but_not_staff_admin(): void
    {
        $client = $this->clientWithEntra();
        $user = User::factory()->create([
            'client_id' => $client->id,
            'role' => UserRole::ClientBillingAdmin,
        ]);

        $this->assertTrue($user->canAccessClientBilling());
        $this->assertTrue($user->canViewClientAdminDashboard());
        $this->assertFalse($user->canViewOrganisationWide());
        $this->assertTrue($user->can('view-my-systems'));
        $this->assertFalse($user->can('view-organisation-wide'));

        $this->actingAs($user)->get(route('dashboard'))->assertOk();
        $this->actingAs($user)->get(route('client-admin.dashboard'))
            ->assertOk()
            ->assertSeeText('Support & Devices');
        $this->actingAs($user)->get(route('microsoft-365.directory'))->assertOk();
        $this->actingAs($user)->post(route('client-admin.refresh'))->assertForbidden();
    }

    public function test_client_admin_sees_org_wide_dashboard(): void
    {
        $client = $this->clientWithEntra();
        $user = User::factory()->create([
            'client_id' => $client->id,
            'role' => UserRole::ClientAdmin,
        ]);

        Cache::put("client:{$client->id}:superops-dashboard:v4", [
            'assets_total' => 5,
            'open_tickets_total' => 2,
            'open_tickets_table' => [
                [
                    'displayId' => '1',
                    'subject' => 'Anyone ticket',
                    'priority' => 'High',
                    'status' => 'Open',
                    'createdTime' => now()->toIso8601String(),
                    'requesterEmail' => 'other@example.test',
                    'requesterName' => 'Other',
                    'requesterUserId' => '',
                ],
            ],
            'tickets_created' => ['7' => 1, '14' => 2, '30' => 3, 'all' => 10],
            'tickets_closed' => ['7' => 1, '14' => 1, '30' => 2, 'all' => 8],
            'last_refreshed_at' => now()->toIso8601String(),
        ], now()->addHour());

        $this->actingAs($user)->get(route('client-admin.dashboard'))
            ->assertOk()
            ->assertSee('Anyone ticket')
            ->assertSeeText('Support & Devices')
            ->assertDontSeeText('My Systems');
        $this->assertTrue($user->can('view-organisation-wide'));
        $this->assertFalse($user->can('view-my-systems'));
        $this->actingAs($user)->get(route('microsoft-365.directory'))->assertOk();
    }

    public function test_client_admin_cannot_access_another_clients_dashboard_via_url(): void
    {
        $clientA = $this->clientWithEntra();
        $clientB = Client::factory()->create(['superops_account_id' => '999']);

        $admin = User::factory()->create([
            'client_id' => $clientA->id,
            'role' => UserRole::ClientAdmin,
        ]);

        Cache::put("client:{$clientB->id}:superops-dashboard:v4", [
            'assets_total' => 99,
            'open_tickets_total' => 99,
            'tickets_created' => ['7' => 99, '14' => 99, '30' => 99, 'all' => 99],
            'tickets_closed' => ['7' => 99, '14' => 99, '30' => 99, 'all' => 99],
            'last_refreshed_at' => now()->toIso8601String(),
        ], now()->addHour());

        $response = $this->actingAs($admin)->get(route('client-admin.dashboard'));

        $response->assertOk();
        $response->assertDontSee('99');
    }

    public function test_role_change_is_logged(): void
    {
        $client = Client::factory()->create();
        $superAdmin = User::factory()->create(['role' => UserRole::SuperAdmin]);
        $user = User::factory()->create([
            'client_id' => $client->id,
            'role' => UserRole::ClientRequester,
        ]);

        $this->actingAs($superAdmin)->put(route('admin.users.update', $user), [
            'client_id' => $client->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => UserRole::ClientAdmin->value,
            'is_active' => true,
        ])->assertRedirect();

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'user.role_changed',
            'subject_id' => $user->id,
        ]);
    }
}
