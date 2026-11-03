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

    public function test_client_admin_can_view_refresh_and_export_own_tenant(): void
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
                ],
            ]),
        ]);

        $client = Client::factory()->create([
            'name' => 'Acme Customer',
            'entra_tenant_id' => '55555555-5555-5555-5555-555555555555',
            'product_entitlements' => ['m365' => ['entitled' => true]],
        ]);
        $clientAdmin = User::factory()->create([
            'client_id' => $client->id,
            'role' => UserRole::ClientAdmin,
        ]);

        $this->actingAs($clientAdmin)
            ->post(route('microsoft-365.mfa-methods.refresh'))
            ->assertRedirect(route('microsoft-365.mfa-methods'));

        $this->actingAs($clientAdmin)
            ->get(route('microsoft-365.mfa-methods'))
            ->assertOk()
            ->assertSee('Sms User')
            ->assertSee('SMS / voice risk')
            ->assertSee('MFA methods')
            ->assertSee('Export CSV');

        $this->actingAs($clientAdmin)
            ->get(route('microsoft-365.mfa-methods.export'))
            ->assertOk()
            ->assertHeader('content-disposition');
    }

    public function test_requester_and_billing_cannot_view_mfa_methods(): void
    {
        config([
            'services.entra_sync.client_id' => 'test-client',
            'services.entra_sync.client_secret' => 'test-secret',
        ]);

        $client = Client::factory()->create([
            'entra_tenant_id' => '66666666-6666-6666-6666-666666666666',
            'product_entitlements' => ['m365' => ['entitled' => true]],
        ]);

        foreach ([UserRole::ClientRequester, UserRole::ClientBillingAdmin] as $role) {
            $viewer = User::factory()->create([
                'client_id' => $client->id,
                'role' => $role,
                'email' => $role->value.'@acme.co.uk',
            ]);

            $this->actingAs($viewer)
                ->get(route('microsoft-365.mfa-methods'))
                ->assertForbidden();
        }
    }
}
