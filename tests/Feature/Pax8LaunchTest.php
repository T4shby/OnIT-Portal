<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Pax8LaunchTest extends TestCase
{
    use RefreshDatabase;

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
    }

    public function test_guest_cannot_access_launch_route(): void
    {
        $this->get(route('integrations.pax8.launch'))
            ->assertRedirect(route('login'));
    }

    public function test_technician_can_launch_partner_portal(): void
    {
        $user = User::factory()->create([
            'role' => UserRole::SuperAdmin,
            'client_id' => null,
            'email' => 'tom.ashby@onit.ltd',
        ]);

        $response = $this->actingAs($user)->get(route('integrations.pax8.launch'));

        $response->assertRedirect('https://app.pax8.com/login?login_hint=tom.ashby%40onit.ltd');
    }

    public function test_client_with_pax8_company_id_can_launch(): void
    {
        $client = Client::factory()->create(['superops_sso_enabled' => true, 'pax8_sso_enabled' => true, 'pax8_company_id' => 'abc-123']);
        $user = User::factory()->create([
            'client_id' => $client->id,
            'role' => UserRole::ClientAdmin,
            'email' => 'approver@customer.example',
        ]);

        $response = $this->actingAs($user)->get(route('integrations.pax8.launch'));

        $response->assertRedirect('https://app.pax8.com/companies/abc-123?login_hint=approver%40customer.example');
    }

    public function test_client_without_pax8_company_id_gets_redirect_with_error(): void
    {
        $client = Client::factory()->create([
            'pax8_company_id' => null,
            'pax8_sso_enabled' => true,
        ]);
        $user = User::factory()->create([
            'client_id' => $client->id,
            'role' => UserRole::ClientRequester,
        ]);

        $response = $this->actingAs($user)->get(route('integrations.pax8.launch'));

        $response->assertRedirect(route('dashboard'));
        $response->assertSessionHas('error');
        $this->assertStringContainsString(
            'Pax8 company ID',
            session('error'),
        );
    }

    public function test_client_without_pax8_sso_enabled_gets_redirect_with_error(): void
    {
        $client = Client::factory()->create([
            'pax8_company_id' => 'abc-123',
            'pax8_sso_enabled' => false,
        ]);
        $user = User::factory()->create([
            'client_id' => $client->id,
            'role' => UserRole::ClientRequester,
        ]);

        $response = $this->actingAs($user)->get(route('integrations.pax8.launch'));

        $response->assertRedirect(route('dashboard'));
        $response->assertSessionHas('error');
        $this->assertStringContainsString(
            'not enabled for your organisation',
            session('error'),
        );
    }
}
