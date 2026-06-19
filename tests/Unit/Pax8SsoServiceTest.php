<?php

namespace Tests\Unit;

use App\Enums\UserRole;
use App\Models\Client;
use App\Models\User;
use App\Services\Pax8\Pax8SsoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Pax8SsoServiceTest extends TestCase
{
    use RefreshDatabase;

    private Pax8SsoService $service;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.pax8.enabled' => true,
            'services.pax8.partner_url' => 'https://app.pax8.com',
            'services.pax8.partner_login_path' => '/login',
            'services.pax8.company_url_template' => 'https://app.pax8.com/companies/{companyId}',
            'services.pax8.login_hint_enabled' => true,
        ]);

        $this->service = app(Pax8SsoService::class);
    }

    public function test_technician_can_launch_partner_portal(): void
    {
        $user = User::factory()->create([
            'role' => UserRole::SuperAdmin,
            'client_id' => null,
            'email' => 'tom.ashby@onit.ltd',
        ]);

        $this->assertTrue($this->service->isEnabledForUser($user));
        $this->assertSame(
            'https://app.pax8.com/login?login_hint=tom.ashby%40onit.ltd',
            $this->service->launchUrlFor($user),
        );
    }

    public function test_client_with_company_id_can_launch(): void
    {
        $client = Client::factory()->create(['pax8_company_id' => 'abc-123']);
        $user = User::factory()->create([
            'client_id' => $client->id,
            'role' => UserRole::ClientUser,
            'email' => 'approver@customer.example',
        ]);

        $this->assertTrue($this->service->isEnabledForUser($user));
        $this->assertSame(
            'https://app.pax8.com/companies/abc-123?login_hint=approver%40customer.example',
            $this->service->launchUrlFor($user),
        );
    }

    public function test_client_without_company_id_is_denied(): void
    {
        $client = Client::factory()->create(['pax8_company_id' => null]);
        $user = User::factory()->create([
            'client_id' => $client->id,
            'role' => UserRole::ClientUser,
        ]);

        $this->assertFalse($this->service->isEnabledForUser($user));
        $this->assertStringContainsString(
            'Pax8 company ID',
            $this->service->accessDeniedHint($user),
        );
    }

    public function test_launch_disabled_when_sso_globally_off(): void
    {
        config(['services.pax8.enabled' => false]);

        $user = User::factory()->create([
            'role' => UserRole::SuperAdmin,
            'client_id' => null,
        ]);

        $this->assertFalse($this->service->isEnabledForUser($user));
        $this->assertSame(
            'Pax8 SSO is not enabled. Contact your administrator.',
            $this->service->accessDeniedHint($user),
        );
    }

    public function test_technician_launch_uses_configured_login_path(): void
    {
        config(['services.pax8.partner_login_path' => '/']);

        $user = User::factory()->create([
            'role' => UserRole::AccountManager,
            'client_id' => null,
            'email' => 'tech@onit.ltd',
        ]);

        $this->assertSame(
            'https://app.pax8.com/?login_hint=tech%40onit.ltd',
            $this->service->launchUrlFor($user),
        );
    }
}
