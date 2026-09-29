<?php

namespace Tests\Feature\Security;

use App\Enums\UserRole;
use App\Models\ActivityLog;
use App\Models\Client;
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
        $this->actingAs($this->manager)->get(route('admin.portal-links.index'))->assertOk()->assertDontSee('Foreign link');
        $this->actingAs($this->manager)->get(route('admin.activity-logs.index'))->assertOk()->assertDontSee('foreign.secret_action');
    }

    public function test_unassigned_account_manager_create_forms_list_no_foreign_clients(): void
    {
        $this->actingAs($this->manager)->get(route('admin.portal-links.create'))->assertOk()->assertDontSee('Foreign Tenant Ltd');
    }

    public function test_unassigned_account_manager_dashboards_are_empty(): void
    {
        $response = $this->actingAs($this->manager)->get(route('admin.dashboard'))->assertOk();
        $response->assertViewHas('stats', fn (array $stats) => $stats['clients'] === 0 && $stats['users'] === 0);
        $response->assertDontSee('foreign.secret_action');

        $this->actingAs($this->manager)->get(route('admin.integration-health.live'))
            ->assertOk()
            ->assertDontSee('Foreign Tenant Ltd');
    }

    public function test_super_admin_still_sees_every_client(): void
    {
        $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);

        $this->actingAs($admin)->get(route('admin.clients.index'))->assertOk()->assertSee('Foreign Tenant Ltd');
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

    public function test_super_admin_audit_trail_includes_staff_side_events(): void
    {
        ActivityLog::create(['client_id' => null, 'action' => 'settings.updated']);
        $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);

        $this->actingAs($admin)->get(route('admin.activity-logs.index'))
            ->assertOk()
            ->assertSee('settings.updated')
            ->assertSee('foreign.secret_action');

        $this->actingAs($admin)->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('settings.updated');

        // Staff-side events are not client data - account managers still never see them.
        $this->actingAs($this->manager)->get(route('admin.activity-logs.index'))
            ->assertOk()
            ->assertDontSee('settings.updated');
    }

    public function test_integration_health_queue_panel_is_scoped_to_assigned_clients(): void
    {
        $mine = Client::factory()->create(['name' => 'Managed Client Co', 'slug' => 'managed-client-co']);
        $this->manager->assignedClients()->attach($mine);

        $payload = fn (int $clientId): string => json_encode([
            'displayName' => \App\Jobs\RefreshSuperOpsDashboardJob::class,
            'data' => ['command' => serialize(new \App\Jobs\RefreshSuperOpsDashboardJob($clientId))],
        ]);

        \Illuminate\Support\Facades\DB::table('jobs')->insert([
            ['queue' => 'high', 'payload' => $payload($this->foreign->id), 'attempts' => 0, 'reserved_at' => null, 'available_at' => time(), 'created_at' => time()],
            ['queue' => 'high', 'payload' => $payload($mine->id), 'attempts' => 0, 'reserved_at' => null, 'available_at' => time(), 'created_at' => time()],
        ]);
        \Illuminate\Support\Facades\DB::table('failed_jobs')->insert([
            ['uuid' => (string) \Illuminate\Support\Str::uuid(), 'connection' => 'database', 'queue' => 'high', 'payload' => $payload($this->foreign->id), 'exception' => "RuntimeException: foreign-failure-detail\n#0 trace", 'failed_at' => now()],
            ['uuid' => (string) \Illuminate\Support\Str::uuid(), 'connection' => 'database', 'queue' => 'high', 'payload' => $payload($mine->id), 'exception' => "RuntimeException: managed-failure-detail\n#0 trace", 'failed_at' => now()],
        ]);

        $this->actingAs($this->manager)->get(route('admin.integration-health.index'))
            ->assertOk()
            ->assertSee('Managed Client Co')
            ->assertSee('managed-failure-detail')
            ->assertDontSee('Foreign Tenant Ltd')
            ->assertDontSee('foreign-failure-detail')
            ->assertDontSee('client #'.$this->foreign->id);

        $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
        $this->actingAs($admin)->get(route('admin.integration-health.index'))
            ->assertOk()
            ->assertSee('Foreign Tenant Ltd')
            ->assertSee('foreign-failure-detail')
            ->assertSee('managed-failure-detail');
    }

    public function test_admin_nav_shows_settings_only_to_those_who_can_open_it(): void
    {
        $this->actingAs($this->manager)->get(route('admin.dashboard'))
            ->assertOk()
            ->assertDontSee(route('admin.settings.index'), false);
        $this->actingAs($this->manager)->get(route('admin.settings.index'))->assertForbidden();

        $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
        $this->actingAs($admin)->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee(route('admin.settings.index'), false);
    }
}
