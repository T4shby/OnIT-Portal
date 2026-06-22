<?php

namespace Tests\Feature\Security;

use App\Enums\UserRole;
use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserClientAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_account_manager_cannot_create_user_for_unassigned_client(): void
    {
        $assigned = Client::factory()->create();
        $other = Client::factory()->create();

        $manager = User::factory()->create([
            'role' => UserRole::AccountManager,
            'client_id' => null,
        ]);
        $manager->assignedClients()->attach($assigned);

        $response = $this->actingAs($manager)->post(route('admin.users.store'), [
            'name' => 'Jane Client',
            'email' => 'jane@clientco.example',
            'role' => UserRole::ClientUser->value,
            'client_id' => $other->id,
            'is_active' => true,
        ]);

        $response->assertSessionHasErrors('client_id');
        $this->assertDatabaseMissing('users', ['email' => 'jane@clientco.example']);
    }

    public function test_account_manager_cannot_move_user_to_another_client(): void
    {
        $clientA = Client::factory()->create();
        $clientB = Client::factory()->create();

        $manager = User::factory()->create([
            'role' => UserRole::AccountManager,
            'client_id' => null,
        ]);
        $manager->assignedClients()->attach([$clientA->id, $clientB->id]);

        $user = User::factory()->create([
            'client_id' => $clientA->id,
            'role' => UserRole::ClientUser,
        ]);

        $response = $this->actingAs($manager)->put(route('admin.users.update', $user), [
            'name' => $user->name,
            'email' => $user->email,
            'role' => UserRole::ClientUser->value,
            'client_id' => $clientB->id,
            'is_active' => true,
        ]);

        $response->assertSessionHasErrors('client_id');
        $this->assertSame($clientA->id, $user->fresh()->client_id);
    }
}
