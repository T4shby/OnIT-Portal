<?php

namespace Tests\Feature\Security;

use App\Enums\UserRole;
use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * users.client_id is nullOnDelete, so deleting a client used to leave its users
 * as active, client-less accounts that appear in no admin listing.
 */
class ClientDeletionOrphanedUsersTest extends TestCase
{
    use RefreshDatabase;

    public function test_deleting_a_client_deactivates_its_users(): void
    {
        $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
        $client = Client::factory()->create();
        $other = Client::factory()->create();
        $member = User::factory()->create(['role' => UserRole::ClientRequester, 'client_id' => $client->id, 'is_active' => true]);
        $bystander = User::factory()->create(['role' => UserRole::ClientRequester, 'client_id' => $other->id, 'is_active' => true]);

        $this->actingAs($admin)->delete(route('admin.clients.destroy', $client))
            ->assertRedirect(route('admin.clients.index'));

        $this->assertNull(Client::find($client->id));
        $member->refresh();
        $this->assertFalse($member->is_active);
        $this->assertNull($member->client_id);
        $this->assertTrue($bystander->fresh()->is_active);
    }

    public function test_existing_orphaned_client_user_session_is_ended(): void
    {
        // An orphan left behind by a deletion before this fix: still is_active.
        $orphan = User::factory()->create(['role' => UserRole::ClientRequester, 'client_id' => null, 'is_active' => true]);

        $this->actingAs($orphan)->get(route('dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_admin_can_open_an_orphaned_user_without_a_500(): void
    {
        $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
        $orphan = User::factory()->create(['role' => UserRole::ClientRequester, 'client_id' => null, 'is_active' => false]);

        $this->actingAs($admin)->get(route('admin.users.edit', $orphan))
            ->assertOk()
            ->assertSee('No company (client deleted)');
    }
}
