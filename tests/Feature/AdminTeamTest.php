<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminTeamTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_view_team_index(): void
    {
        $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
        User::factory()->create(['role' => UserRole::AccountManager, 'name' => 'Jane Manager']);

        $response = $this->actingAs($admin)->get(route('admin.team.index'));

        $response->assertOk();
        $response->assertSee('Jane Manager');
        $response->assertSee('Add team member');
    }

    public function test_account_manager_cannot_access_team(): void
    {
        $manager = User::factory()->create(['role' => UserRole::AccountManager]);

        $this->actingAs($manager)->get(route('admin.team.index'))->assertForbidden();
        $this->actingAs($manager)->get(route('admin.team.create'))->assertForbidden();
    }

    public function test_super_admin_can_add_team_member(): void
    {
        $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
        $client = Client::factory()->create();

        $response = $this->actingAs($admin)->post(route('admin.team.store'), [
            'name' => 'New Technician',
            'email' => 'tech@onit.ltd',
            'role' => UserRole::AccountManager->value,
            'assigned_clients' => [$client->id],
            'is_active' => true,
        ]);

        $response->assertRedirect(route('admin.team.index'));
        $this->assertDatabaseHas('users', [
            'email' => 'tech@onit.ltd',
            'role' => UserRole::AccountManager->value,
            'client_id' => null,
        ]);
        $this->assertTrue(
            User::where('email', 'tech@onit.ltd')->first()->assignedClients()->where('clients.id', $client->id)->exists()
        );
    }

    public function test_client_user_create_requires_client(): void
    {
        $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);

        $this->actingAs($admin)->get(route('admin.users.create'))->assertNotFound();
    }

    public function test_editing_team_member_redirects_from_users_edit(): void
    {
        $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
        $technician = User::factory()->create(['role' => UserRole::AccountManager, 'client_id' => null]);

        $response = $this->actingAs($admin)->get(route('admin.users.edit', $technician));

        $response->assertRedirect(route('admin.team.edit', $technician));
    }

    public function test_editing_account_manager_keeps_inactive_client_assignments(): void
    {
        $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
        $active = Client::factory()->create(['name' => 'Active Co', 'is_active' => true]);
        $dropped = Client::factory()->create(['name' => 'Unticked Co', 'is_active' => true]);
        $inactive = Client::factory()->create(['name' => 'Dormant Co', 'is_active' => false]);
        $manager = User::factory()->create(['role' => UserRole::AccountManager, 'client_id' => null]);
        $manager->assignedClients()->sync([$active->id, $dropped->id, $inactive->id]);

        $this->actingAs($admin)->get(route('admin.team.edit', $manager))
            ->assertOk()
            ->assertSee('Dormant Co');

        // The form only renders active-client checkboxes; the admin unticks one.
        $this->actingAs($admin)->put(route('admin.team.update', $manager), [
            'name' => $manager->name,
            'email' => $manager->email,
            'role' => UserRole::AccountManager->value,
            'is_active' => '1',
            'assigned_clients' => [$active->id],
        ])->assertRedirect(route('admin.team.index'));

        $ids = $manager->assignedClients()->pluck('clients.id')->sort()->values()->all();
        $this->assertSame([$active->id, $inactive->id], $ids);
    }

    public function test_changing_role_away_from_account_manager_still_clears_all_assignments(): void
    {
        $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
        $inactive = Client::factory()->create(['is_active' => false]);
        $manager = User::factory()->create(['role' => UserRole::AccountManager, 'client_id' => null]);
        $manager->assignedClients()->sync([$inactive->id]);

        $this->actingAs($admin)->put(route('admin.team.update', $manager), [
            'name' => $manager->name,
            'email' => $manager->email,
            'role' => UserRole::SuperAdmin->value,
            'is_active' => '1',
        ])->assertRedirect(route('admin.team.index'));

        $this->assertSame(0, $manager->assignedClients()->count());
    }

    public function test_super_admin_cannot_demote_or_deactivate_themselves(): void
    {
        $admin = User::factory()->create(['role' => UserRole::SuperAdmin, 'is_active' => true]);

        $this->actingAs($admin)->put(route('admin.team.update', $admin), [
            'name' => $admin->name,
            'email' => $admin->email,
            'role' => UserRole::AccountManager->value,
            'is_active' => '1',
        ])->assertSessionHas('error');

        $this->actingAs($admin)->put(route('admin.team.update', $admin), [
            'name' => $admin->name,
            'email' => $admin->email,
            'role' => UserRole::SuperAdmin->value,
            'is_active' => '0',
        ])->assertSessionHas('error');

        $admin->refresh();
        $this->assertSame(UserRole::SuperAdmin, $admin->role);
        $this->assertTrue($admin->is_active);

        // Editing their own name is still fine.
        $this->actingAs($admin)->put(route('admin.team.update', $admin), [
            'name' => 'Renamed Admin',
            'email' => $admin->email,
            'role' => UserRole::SuperAdmin->value,
            'is_active' => '1',
        ])->assertRedirect(route('admin.team.index'));
        $this->assertSame('Renamed Admin', $admin->fresh()->name);
    }
}
