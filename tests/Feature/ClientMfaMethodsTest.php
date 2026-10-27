<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ClientMfaMethodsTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_can_refresh_and_see_sms_voice_users(): void
    {
        config([
            'services.entra_sync.client_id' => 'test-client',
            'services.entra_sync.client_secret' => 'test-secret',
        ]);

        Http::fake([
            'login.microsoftonline.com/*' => Http::response([
                'access_token' => 'tok',
                'expires_in' => 3600,
            ]),
            'graph.microsoft.com/v1.0/reports/authenticationMethods/userRegistrationDetails*' => Http::response([
                'value' => [
                    [
                        'id' => 'aaa',
                        'userPrincipalName' => 'sms.user@acme.co.uk',
                        'userDisplayName' => 'Sms User',
                        'userType' => 'member',
                        'isAdmin' => false,
                        'isMfaRegistered' => true,
                        'isMfaCapable' => true,
                        'isPasswordlessCapable' => false,
                        'methodsRegistered' => ['mobilePhone'],
                        'systemPreferredAuthenticationMethods' => ['sms'],
                        'lastUpdatedDateTime' => '2026-10-01T00:00:00Z',
                    ],
                    [
                        'id' => 'bbb',
                        'userPrincipalName' => 'auth.user@acme.co.uk',
                        'userDisplayName' => 'Auth User',
                        'userType' => 'member',
                        'isAdmin' => false,
                        'isMfaRegistered' => true,
                        'isMfaCapable' => true,
                        'isPasswordlessCapable' => true,
                        'methodsRegistered' => ['microsoftAuthenticatorPush', 'passKeyDeviceBound'],
                        'systemPreferredAuthenticationMethods' => ['push'],
                        'lastUpdatedDateTime' => '2026-10-01T00:00:00Z',
                    ],
                ],
            ]),
        ]);

        $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
        $client = Client::factory()->create([
            'name' => 'Acme',
            'entra_tenant_id' => '44444444-4444-4444-4444-444444444444',
        ]);

        $this->actingAs($admin)
            ->post(route('admin.clients.mfa-methods.refresh', $client))
            ->assertRedirect(route('admin.clients.mfa-methods', $client));

        $this->actingAs($admin)
            ->get(route('admin.clients.mfa-methods', $client))
            ->assertOk()
            ->assertSee('Sms User')
            ->assertSee('Auth User')
            ->assertSee('SMS / voice risk')
            ->assertSee('Export CSV');

        $this->actingAs($admin)
            ->get(route('admin.clients.mfa-methods.export', $client))
            ->assertOk()
            ->assertHeader('content-disposition');
    }
}
