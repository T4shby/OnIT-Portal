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
        $client = Client::factory()->create([
            'pax8_company_id' => 'abc-123',
            'pax8_sso_enabled' => true,
        ]);
        $user = User::factory()->create([
            'client_id' => $client->id,
            'role' => UserRole::ClientRequester,
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
        $client = Client::factory()->create([
            'pax8_company_id' => null,
            'pax8_sso_enabled' => true,
        ]);
        $user = User::factory()->create([
            'client_id' => $client->id,
            'role' => UserRole::ClientRequester,
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

    public function test_technician_launch_falls_back_to_app_pax8_when_custom_url_configured(): void
    {
        config(['services.pax8.partner_url' => 'https://onit.mycommandconsole.com']);

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

    public function test_company_id_alone_enables_launch(): void
    {
        $client = Client::factory()->create([
            'pax8_company_id' => 'abc-123',
            'pax8_sso_enabled' => false,
        ]);
        $user = User::factory()->create([
            'client_id' => $client->id,
            'role' => UserRole::ClientRequester,
            'email' => 'approver@customer.example',
        ]);

        $this->assertTrue($this->service->isEnabledForUser($user));
        $this->assertSame(
            'https://app.pax8.com/companies/abc-123?login_hint=approver%40customer.example',
            $this->service->launchUrlFor($user),
        );
    }

    public function test_company_launch_url_falls_back_to_app_pax8_host(): void
    {
        config(['services.pax8.company_url_template' => 'https://evil.example/companies/{companyId}']);

        $client = Client::factory()->create([
            'pax8_company_id' => 'abc-123',
            'pax8_sso_enabled' => true,
        ]);
        $user = User::factory()->create([
            'client_id' => $client->id,
            'role' => UserRole::ClientRequester,
            'email' => 'approver@customer.example',
        ]);

        $this->assertSame(
            'https://app.pax8.com/companies/abc-123?login_hint=approver%40customer.example',
            $this->service->launchUrlFor($user),
        );
    }
}
