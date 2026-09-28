<?php

namespace Tests\Feature\Security;

use App\Enums\UserRole;
use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\ClientNotice;
use App\Models\ClientOpportunity;
use App\Models\ClientRecommendation;
use App\Models\PortalLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * accessibleClientIds() returning [] must mean "no clients", never "every client".
 *
 * An account manager with no assigned clients is a normal state (TeamController lets
 * you create one, or untick every client) - admin listings previously skipped the
 * whereIn() for an empty list and showed every tenant's data.
 */
class AccountManagerScopeTest extends TestCase
{
    use RefreshDatabase;

    private Client $foreign;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->foreign = Client::factory()->create(['name' => 'Foreign Tenant Ltd', 'slug' => 'foreign-tenant-ltd']);

        User::factory()->create([
            'name' => 'Foreign Person',
            'role' => UserRole::ClientAdmin,
            'client_id' => $this->foreign->id,
        ]);

        ClientNotice::create(['client_id' => $this->foreign->id, 'title' => 'Foreign notice', 'body' => 'x', 'is_active' => true]);
        ClientRecommendation::create(['client_id' => $this->foreign->id, 'title' => 'Foreign recommendation', 'body' => 'x', 'category' => 'security', 'priority' => 'high', 'is_active' => true]);
        ClientOpportunity::create(['client_id' => $this->foreign->id, 'title' => 'Foreign opportunity', 'body' => 'x', 'category' => 'backup', 'status' => 'open', 'is_active' => true]);
        PortalLink::create(['client_id' => $this->foreign->id, 'name' => 'Foreign link', 'url' => 'https://foreign.example', 'link_type' => 'external', 'is_active' => true]);
        ActivityLog::create(['client_id' => $this->foreign->id, 'action' => 'foreign.secret_action']);

        $this->manager = User::factory()->create(['role' => UserRole::AccountManager, 'client_id' => null]);
    }

    public function test_unassigned_account_manager_sees_no_foreign_clients(): void
    {
        $this->actingAs($this->manager)->get(route('admin.clients.index'))
            ->assertOk()
            ->assertDontSee('Foreign Tenant Ltd');

        $this->actingAs($this->manager)->get(route('admin.clients.graph-reconsent'))
            ->assertOk()
            ->assertDontSee('Foreign Tenant Ltd');

        $this->actingAs($this->manager)->get(route('admin.users.index'))
            ->assertOk()
            ->assertDontSee('Foreign Tenant Ltd');
    }

    public function test_unassigned_account_manager_sees_no_foreign_content_or_logs(): void
    {
        $this->actingAs($this->manager)->get(route('admin.notices.index'))->assertOk()->assertDontSee('Foreign notice');
        $this->actingAs($this->manager)->get(route('admin.recommendations.index'))->assertOk()->assertDontSee('Foreign recommendation');
        $this->actingAs($this->manager)->get(route('admin.opportunities.index'))->assertOk()->assertDontSee('Foreign opportunity');
        $this->actingAs($this->manager)->get(route('admin.portal-links.index'))->assertOk()->assertDontSee('Foreign link');
        $this->actingAs($this->manager)->get(route('admin.activity-logs.index'))->assertOk()->assertDontSee('foreign.secret_action');
    }

    public function test_unassigned_account_manager_create_forms_list_no_foreign_clients(): void
    {
        foreach (['admin.notices.create', 'admin.recommendations.create', 'admin.opportunities.create', 'admin.portal-links.create'] as $route) {
            $this->actingAs($this->manager)->get(route($route))->assertOk()->assertDontSee('Foreign Tenant Ltd');
        }
    }

    public function test_unassigned_account_manager_dashboards_are_empty(): void
    {
        $response = $this->actingAs($this->manager)->get(route('admin.dashboard'))->assertOk();
        $response->assertViewHas('stats', fn (array $stats) => $stats['clients'] === 0 && $stats['users'] === 0 && $stats['notices'] === 0);
        $response->assertDontSee('foreign.secret_action');

        $this->actingAs($this->manager)->get(route('admin.integration-health.live'))
            ->assertOk()
            ->assertDontSee('Foreign Tenant Ltd');
    }

    public function test_super_admin_still_sees_every_client(): void
    {
        $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);

        $this->actingAs($admin)->get(route('admin.clients.index'))->assertOk()->assertSee('Foreign Tenant Ltd');
        $this->actingAs($admin)->get(route('admin.notices.index'))->assertOk()->assertSee('Foreign notice');
    }

    public function test_assigned_account_manager_sees_only_assigned_client(): void
    {
        $mine = Client::factory()->create(['name' => 'Managed Client Co', 'slug' => 'managed-client-co']);
        $this->manager->assignedClients()->attach($mine);

        $this->actingAs($this->manager)->get(route('admin.clients.index'))
            ->assertOk()
            ->assertSee('Managed Client Co')
            ->assertDontSee('Foreign Tenant Ltd');
    }
}
