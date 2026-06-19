<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SuperOpsLaunchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.superops.sso_enabled' => true,
            'services.superops.technician_portal_url' => 'https://portal.onit.ltd',
            'services.superops.technician_login_path' => '/#/technician/login',
            'services.superops.requester_portal_url' => 'https://portal.onit.ltd',
            'services.superops.requester_login_path' => '/#/requester/login',
            'services.superops.login_hint_enabled' => true,
        ]);
    }

    public function test_guest_cannot_access_launch_route(): void
    {
        $this->get(route('integrations.superops.launch'))
            ->assertRedirect(route('login'));
    }

    public function test_technician_can_launch_msp_portal(): void
    {
        $user = User::factory()->create([
            'role' => UserRole::SuperAdmin,
            'client_id' => null,
            'email' => 'tom.ashby@onit.ltd',
        ]);

        $response = $this->actingAs($user)->get(route('integrations.superops.launch'));

        $response->assertRedirect('https://portal.onit.ltd/?login_hint=tom.ashby%40onit.ltd#/technician/login');
    }

    public function test_client_with_sso_enabled_can_launch_requester_portal(): void
    {
        $client = Client::factory()->create(['superops_sso_enabled' => true]);
        $user = User::factory()->create([
            'client_id' => $client->id,
            'role' => UserRole::ClientUser,
            'email' => 'portal.test@onit.ltd',
        ]);

        $response = $this->actingAs($user)->get(route('integrations.superops.launch'));

        $response->assertRedirect('https://portal.onit.ltd/?login_hint=portal.test%40onit.ltd#/requester/login');
    }

    public function test_client_without_sso_enabled_gets_redirect_with_error(): void
    {
        $client = Client::factory()->create(['superops_sso_enabled' => false]);
        $user = User::factory()->create([
            'client_id' => $client->id,
            'role' => UserRole::ClientUser,
        ]);

        $response = $this->actingAs($user)->get(route('integrations.superops.launch'));

        $response->assertRedirect(route('dashboard'));
        $response->assertSessionHas('error');
    }

    public function test_technician_without_portal_config_gets_redirect_with_error(): void
    {
        config([
            'services.superops.technician_portal_url' => null,
            'services.superops.portal_url' => 'https://app.superops.ai',
        ]);

        $user = User::factory()->create([
            'role' => UserRole::SuperAdmin,
            'client_id' => null,
        ]);

        $response = $this->actingAs($user)->get(route('integrations.superops.launch'));

        $response->assertRedirect(route('dashboard'));
        $response->assertSessionHas('error');
    }
}
