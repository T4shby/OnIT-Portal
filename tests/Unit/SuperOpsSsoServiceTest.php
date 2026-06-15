<?php

namespace Tests\Unit;

use App\Enums\UserRole;
use App\Models\Client;
use App\Models\User;
use App\Services\SuperOps\SuperOpsSsoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SuperOpsSsoServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_user_with_sso_enabled_can_launch(): void
    {
        config([
            'services.superops.requester_portal_url' => 'https://portal.onit.ltd',
            'services.superops.requester_login_path' => '/#/requester/login',
            'services.superops.login_hint_enabled' => true,
        ]);

        $client = Client::factory()->create(['superops_sso_enabled' => true]);
        $user = User::factory()->create([
            'client_id' => $client->id,
            'role' => UserRole::ClientUser,
            'email' => 'portal.test@onit.ltd',
        ]);

        $service = app(SuperOpsSsoService::class);

        $this->assertTrue($service->isEnabledForUser($user));
        $this->assertSame(
            'https://portal.onit.ltd/?login_hint=portal.test%40onit.ltd#/requester/login',
            $service->launchUrlFor($user),
        );
    }

    public function test_super_admin_cannot_launch_as_requester(): void
    {
        config([
            'services.superops.requester_portal_url' => 'https://portal.onit.ltd',
            'services.superops.sso_enabled' => true,
        ]);

        $user = User::factory()->create([
            'client_id' => null,
            'role' => UserRole::SuperAdmin,
            'email' => 'tom.ashby@onit.ltd',
        ]);

        $service = app(SuperOpsSsoService::class);

        $this->assertFalse($service->isEnabledForUser($user));
        $this->assertStringContainsString('client account', $service->accessDeniedHint($user));
    }
}
