<?php

namespace Tests\Feature;

use App\Enums\UserProvisionSource;
use App\Enums\UserRole;
use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ClientEntraSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.entra_sync.enabled' => true,
            'services.entra_sync.client_id' => 'test-client-id',
            'services.entra_sync.client_secret' => 'test-secret',
        ]);
    }

    public function test_super_admin_can_dry_run_entra_sync(): void
    {
        $tenantId = '11111111-1111-1111-1111-111111111111';

        $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
        $client = Client::factory()->create([
            'entra_tenant_id' => $tenantId,
            'entra_sync_enabled' => true,
        ]);

        $this->fakeTenantSyncGraph($tenantId, [
            'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa' => [
                'mail' => 'jane@acme.com',
                'userPrincipalName' => 'jane@acme.com',
                'displayName' => 'Jane Smith',
                'accountEnabled' => true,
                'licensed' => true,
                'mailboxPurpose' => 'user',
            ],
        ]);

        $response = $this->actingAs($admin)
            ->post(route('admin.clients.sync-entra', $client), ['dry_run' => '1']);

        $response->assertRedirect();
        $response->assertSessionHas('success', fn (string $message) => str_contains($message, 'Dry run'));
        $this->assertDatabaseMissing('users', ['email' => 'jane@acme.com']);
    }

    public function test_super_admin_can_run_entra_sync(): void
    {
        $tenantId = '11111111-1111-1111-1111-111111111111';

        $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
        $client = Client::factory()->create([
            'entra_tenant_id' => $tenantId,
            'entra_sync_enabled' => true,
        ]);

        $this->fakeTenantSyncGraph($tenantId, [
            'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa' => [
                'mail' => 'jane@acme.com',
                'userPrincipalName' => 'jane@acme.com',
                'displayName' => 'Jane Smith',
                'accountEnabled' => true,
                'licensed' => true,
                'mailboxPurpose' => 'user',
            ],
        ]);

        $response = $this->actingAs($admin)
            ->post(route('admin.clients.sync-entra', $client));

        $response->assertRedirect();
        $response->assertSessionHas('success');
        $this->assertDatabaseHas('users', [
            'email' => 'jane@acme.com',
            'name' => 'Jane Smith (User)',
            'client_id' => $client->id,
            'provisioned_by' => UserProvisionSource::EntraSync->value,
        ]);
    }

    public function test_client_user_cannot_run_entra_sync(): void
    {
        $client = Client::factory()->create([
            'entra_tenant_id' => '11111111-1111-1111-1111-111111111111',
            'entra_sync_enabled' => true,
        ]);

        $user = User::factory()->create([
            'client_id' => $client->id,
            'role' => UserRole::ClientUser,
        ]);

        $response = $this->actingAs($user)
            ->post(route('admin.clients.sync-entra', $client));

        $response->assertForbidden();
    }

    public function test_sync_returns_error_when_globally_disabled(): void
    {
        config(['services.entra_sync.enabled' => false]);

        $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
        $client = Client::factory()->create([
            'entra_tenant_id' => '11111111-1111-1111-1111-111111111111',
            'entra_sync_enabled' => true,
        ]);

        $response = $this->actingAs($admin)
            ->post(route('admin.clients.sync-entra', $client));

        $response->assertRedirect();
        $response->assertSessionHas('error');
    }

    public function test_shared_mailbox_user_cannot_sign_in_via_microsoft_oauth(): void
    {
        $client = Client::factory()->create();

        User::factory()->create([
            'client_id' => $client->id,
            'email' => 'accounts@acme.com',
            'role' => UserRole::ClientUser,
            'is_active' => true,
            'portal_login_enabled' => false,
            'entra_object_id' => 'dddddddd-dddd-dddd-dddd-dddddddddddd',
        ]);

        $this->mockMicrosoftSocialiteUser('accounts@acme.com', 'dddddddd-dddd-dddd-dddd-dddddddddddd');

        $response = $this->get(route('auth.microsoft.callback'));

        $response->assertRedirect(route('login'));
        $response->assertSessionHas('error', fn (string $message) => str_contains($message, 'Shared mailboxes'));
        $this->assertGuest();
    }

    public function test_super_admin_can_save_onboarding_checklist(): void
    {
        $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
        $client = Client::factory()->create();

        $response = $this->actingAs($admin)
            ->put(route('admin.clients.onboarding.update', $client), [
                'checkpoints' => [
                    'entra_group_created' => '1',
                    'superops_scim_configured' => '1',
                ],
            ]);

        $response->assertRedirect(route('admin.clients.edit', $client));
        $response->assertSessionHas('success');

        $client->refresh();
        $this->assertTrue($client->onboarding_checklist['entra_group_created']);
        $this->assertTrue($client->onboarding_checklist['superops_scim_configured']);
        $this->assertFalse($client->onboarding_checklist['handed_off'] ?? false);
    }

    public function test_marking_entra_group_step_complete_shows_done_on_edit(): void
    {
        $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
        $client = Client::factory()->create([
            'entra_tenant_id' => 'f95a6006-f34e-4634-8678-32ab856d9756',
            'entra_group_id' => null,
        ]);

        $this->actingAs($admin)
            ->put(route('admin.clients.onboarding.update', $client), [
                'checkpoints' => ['entra_group_created' => '1'],
            ])
            ->assertRedirect(route('admin.clients.edit', $client));

        $client->refresh();

        $step = collect(app(\App\Services\ClientOnboardingService::class)->steps($client))
            ->firstWhere('key', 'entra_group_created');

        $this->assertTrue($step['complete']);
    }

    public function test_checklist_save_preserves_other_manual_ticks(): void
    {
        $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
        $client = Client::factory()->create([
            'onboarding_checklist' => ['superops_scim_configured' => true],
        ]);

        $this->actingAs($admin)
            ->put(route('admin.clients.onboarding.update', $client), [
                'checkpoints' => ['entra_group_created' => '1'],
            ]);

        $client->refresh();

        $this->assertTrue($client->onboarding_checklist['entra_group_created']);
        $this->assertTrue($client->onboarding_checklist['superops_scim_configured']);
    }

    /**
     * @param  array<string, array<string, mixed>>  $usersById
     */
    private function fakeTenantSyncGraph(string $tenantId, array $usersById): void
    {
        $list = [];

        foreach ($usersById as $id => $user) {
            $list[] = [
                'id' => $id,
                'mail' => $user['mail'],
                'userPrincipalName' => $user['userPrincipalName'],
                'displayName' => $user['displayName'],
                'accountEnabled' => $user['accountEnabled'],
            ];
        }

        Http::fake(function ($request) use ($tenantId, $usersById, $list) {
            $url = $request->url();

            if ($url === "https://login.microsoftonline.com/{$tenantId}/oauth2/v2.0/token") {
                return Http::response(['access_token' => 'fake-token', 'expires_in' => 3600]);
            }

            if (str_starts_with($url, 'https://graph.microsoft.com/v1.0/users?')) {
                return Http::response(['value' => $list]);
            }

            if (preg_match('#/users/([0-9a-f-]+)/licenseDetails$#', $url, $matches)) {
                $userId = $matches[1];
                $licensed = $usersById[$userId]['licensed'] ?? false;

                return Http::response([
                    'value' => $licensed ? [['skuId' => 'test-sku', 'skuPartNumber' => 'O365_BUSINESS']] : [],
                ]);
            }

            if (preg_match('#/users/([0-9a-f-]+)/mailboxSettings#', $url, $matches)) {
                $userId = $matches[1];
                $purpose = $usersById[$userId]['mailboxPurpose'] ?? null;

                return Http::response([
                    'userPurpose' => $purpose,
                ]);
            }

            return Http::response([], 404);
        });
    }

    private function mockMicrosoftSocialiteUser(string $email, string $objectId): void
    {
        config(['services.azure.oauth_stateless' => true]);

        $socialiteUser = new class($email, $objectId)
        {
            public function __construct(private string $email, private string $objectId) {}

            public function getEmail(): string
            {
                return $this->email;
            }

            public function getId(): string
            {
                return $this->objectId;
            }

            public function getName(): string
            {
                return 'Accounts';
            }

            public string $token = 'token';

            public ?string $refreshToken = null;

            public ?int $expiresIn = 3600;
        };

        $driver = \Mockery::mock('Laravel\Socialite\Contracts\Provider');
        $driver->shouldReceive('redirectUrl')->andReturnSelf();
        $driver->shouldReceive('scopes')->andReturnSelf();
        $driver->shouldReceive('stateless')->andReturnSelf();
        $driver->shouldReceive('user')->andReturn($socialiteUser);

        \Laravel\Socialite\Facades\Socialite::shouldReceive('driver')
            ->with('azure')
            ->andReturn($driver);
    }
}
