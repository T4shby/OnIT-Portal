<?php

namespace Tests\Feature\Security;

use App\Enums\UserRole;
use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Deactivation must end access that already exists (session / remember-me cookie),
 * not only block the next Microsoft sign-in.
 */
class DeactivatedAccountSessionTest extends TestCase
{
    use RefreshDatabase;

    private function clientAdmin(Client $client): User
    {
        return User::factory()->create([
            'role' => UserRole::ClientAdmin,
            'client_id' => $client->id,
        ]);
    }

    public function test_active_user_keeps_access(): void
    {
        $user = $this->clientAdmin(Client::factory()->create());

        $this->actingAs($user)->get(route('dashboard'))->assertOk();
    }

    public function test_user_deactivated_mid_session_is_signed_out(): void
    {
        $user = $this->clientAdmin(Client::factory()->create());
        $this->actingAs($user);

        $user->update(['is_active' => false]);

        $this->get(route('dashboard'))
            ->assertRedirect(route('login'))
            ->assertSessionHas('error', fn (string $m) => str_contains($m, 'deactivated'));
        $this->assertGuest();
    }

    public function test_portal_login_disabled_mid_session_is_signed_out(): void
    {
        $user = $this->clientAdmin(Client::factory()->create());
        $this->actingAs($user);

        $user->update(['portal_login_enabled' => false]);

        $this->get(route('dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_user_of_deactivated_client_is_signed_out(): void
    {
        $client = Client::factory()->create();
        $user = $this->clientAdmin($client);
        $this->actingAs($user);

        $client->update(['is_active' => false]);

        $this->get(route('dashboard'))
            ->assertRedirect(route('login'))
            ->assertSessionHas('error', fn (string $m) => str_contains($m, 'organisation is not active'));
        $this->assertGuest();
    }

    public function test_deactivated_staff_lose_admin_access(): void
    {
        $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
        $this->actingAs($admin);

        $admin->update(['is_active' => false]);

        $this->get(route('admin.dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
    }
}
