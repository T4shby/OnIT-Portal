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
}
