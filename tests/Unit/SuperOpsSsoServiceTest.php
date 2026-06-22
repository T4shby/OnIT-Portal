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
            'services.superops.sso_enabled' => true,
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
            'https://portal.onit.ltd/#/requester/login?login_hint=portal.test%40onit.ltd',
            $service->launchUrlFor($user),
        );
    }

    public function test_super_admin_can_launch_technician_portal(): void
    {
        config([
            'services.superops.sso_enabled' => true,
            'services.superops.technician_portal_url' => 'https://portal.onit.ltd',
            'services.superops.technician_login_path' => '/#/technician/login',
            'services.superops.login_hint_enabled' => true,
        ]);

        $user = User::factory()->create([
            'client_id' => null,
            'role' => UserRole::SuperAdmin,
            'email' => 'tom.ashby@onit.ltd',
        ]);

        $service = app(SuperOpsSsoService::class);

        $this->assertTrue($service->isEnabledForUser($user));
        $this->assertSame(
            'https://portal.onit.ltd/#/technician/login?login_hint=tom.ashby%40onit.ltd',
            $service->launchUrlFor($user),
        );
    }

    public function test_client_user_without_sso_enabled_is_blocked(): void
    {
        config([
            'services.superops.sso_enabled' => true,
            'services.superops.requester_portal_url' => 'https://portal.onit.ltd',
        ]);

        $client = Client::factory()->create(['superops_sso_enabled' => false]);
        $user = User::factory()->create([
            'client_id' => $client->id,
            'role' => UserRole::ClientUser,
        ]);

        $service = app(SuperOpsSsoService::class);

        $this->assertFalse($service->isEnabledForUser($user));
        $this->assertStringContainsString('not enabled for your organisation', $service->accessDeniedHint($user));
    }

    public function test_technician_launch_inherits_requester_portal_host(): void
    {
        config([
            'services.superops.sso_enabled' => true,
            'services.superops.technician_portal_url' => 'https://portal.onit.ltd',
            'services.superops.technician_login_path' => '/#/technician/login',
            'services.superops.login_hint_enabled' => true,
        ]);

        $user = User::factory()->create([
            'client_id' => null,
            'role' => UserRole::AccountManager,
            'email' => 'tech@onit.ltd',
        ]);

        $service = app(SuperOpsSsoService::class);

        $this->assertTrue($service->isEnabledForUser($user));
        $this->assertSame(
            'https://portal.onit.ltd/#/technician/login?login_hint=tech%40onit.ltd',
            $service->launchUrlFor($user),
        );
    }

    public function test_technician_launch_uses_subdomain_host_when_configured(): void
    {
        config([
            'services.superops.sso_enabled' => true,
            'services.superops.technician_portal_url' => 'https://onitltd.superops.ai',
            'services.superops.technician_login_path' => '/#/technician/login',
            'services.superops.login_hint_enabled' => true,
        ]);

        $user = User::factory()->create([
            'client_id' => null,
            'role' => UserRole::SuperAdmin,
            'email' => 'tech@onit.ltd',
        ]);

        $service = app(SuperOpsSsoService::class);

        $this->assertTrue($service->isEnabledForUser($user));
        $this->assertSame(
            'https://onitltd.superops.ai/#/technician/login?login_hint=tech%40onit.ltd',
            $service->launchUrlFor($user),
        );
    }

    public function test_super_admin_blocked_when_only_msp_app_url_configured(): void
    {
        config([
            'services.superops.sso_enabled' => true,
            'services.superops.technician_portal_url' => null,
            'services.superops.portal_url' => 'https://app.superops.ai',
        ]);

        $user = User::factory()->create([
            'client_id' => null,
            'role' => UserRole::SuperAdmin,
        ]);

        $service = app(SuperOpsSsoService::class);

        $this->assertFalse($service->isEnabledForUser($user));
        $this->assertStringContainsString('SUPEROPS_SUBDOMAIN or SUPEROPS_REQUESTER_PORTAL_URL', $service->accessDeniedHint($user));
    }

    public function test_malicious_superops_sso_url_is_ignored(): void
    {
        config([
            'services.superops.sso_enabled' => true,
            'services.superops.sso_url' => 'https://evil.example/phish',
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

        $this->assertSame(
            'https://portal.onit.ltd/#/requester/login?login_hint=portal.test%40onit.ltd',
            $service->launchUrlFor($user),
        );
    }

    public function test_technician_launch_falls_back_when_env_path_truncated_at_hash(): void
    {
        config([
            'services.superops.sso_enabled' => true,
            'services.superops.technician_portal_url' => 'https://portal.onit.ltd',
            'services.superops.technician_login_path' => '/',
            'services.superops.login_hint_enabled' => true,
        ]);

        $user = User::factory()->create([
            'client_id' => null,
            'role' => UserRole::SuperAdmin,
            'email' => 'tech@onit.ltd',
        ]);

        $service = app(SuperOpsSsoService::class);

        $this->assertSame(
            'https://portal.onit.ltd/#/technician/login?login_hint=tech%40onit.ltd',
            $service->launchUrlFor($user),
        );
    }
}
