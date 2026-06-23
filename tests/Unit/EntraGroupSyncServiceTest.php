<?php

namespace Tests\Unit;

use App\Enums\EntraIdentityType;
use App\Enums\UserProvisionSource;
use App\Enums\UserRole;
use App\Models\Client;
use App\Models\User;
use App\Services\EntraSync\EntraGroupSyncService;
use App\Services\EntraSync\EntraSyncDisplayName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EntraGroupSyncServiceTest extends TestCase
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

    public function test_sync_creates_licensed_users_with_formatted_display_name(): void
    {
        $tenantId = '11111111-1111-1111-1111-111111111111';

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
            'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb' => [
                'mail' => 'bob@acme.com',
                'userPrincipalName' => 'bob@acme.com',
                'displayName' => 'Bob Jones',
                'accountEnabled' => true,
                'licensed' => true,
                'mailboxPurpose' => 'user',
            ],
        ]);

        $result = app(EntraGroupSyncService::class)->syncClient($client);

        $this->assertSame(2, $result->created);
        $this->assertDatabaseHas('users', [
            'email' => 'jane@acme.com',
            'name' => 'Jane Smith (User)',
            'client_id' => $client->id,
            'entra_identity_type' => EntraIdentityType::User->value,
            'portal_login_enabled' => true,
            'provisioned_by' => UserProvisionSource::EntraSync->value,
            'is_active' => true,
        ]);
    }

    public function test_sync_creates_shared_mailbox_without_portal_login(): void
    {
        $tenantId = '11111111-1111-1111-1111-111111111111';

        $client = Client::factory()->create([
            'entra_tenant_id' => $tenantId,
            'entra_sync_enabled' => true,
        ]);

        $this->fakeTenantSyncGraph($tenantId, [
            'dddddddd-dddd-dddd-dddd-dddddddddddd' => [
                'mail' => 'accounts@acme.com',
                'userPrincipalName' => 'accounts@acme.com',
                'displayName' => 'Accounts',
                'accountEnabled' => false,
                'licensed' => false,
                'mailboxPurpose' => 'shared',
            ],
        ]);

        app(EntraGroupSyncService::class)->syncClient($client);

        $this->assertDatabaseHas('users', [
            'email' => 'accounts@acme.com',
            'name' => 'Accounts (Shared Mailbox)',
            'entra_identity_type' => EntraIdentityType::SharedMailbox->value,
            'portal_login_enabled' => false,
            'is_active' => true,
        ]);
    }

    public function test_sync_skips_unlicensed_users_that_are_not_shared_mailboxes(): void
    {
        $tenantId = '11111111-1111-1111-1111-111111111111';

        $client = Client::factory()->create([
            'entra_tenant_id' => $tenantId,
            'entra_sync_enabled' => true,
        ]);

        $this->fakeTenantSyncGraph($tenantId, [
            'eeeeeeee-eeee-eeee-eeee-eeeeeeeeeeee' => [
                'mail' => 'service@acme.com',
                'userPrincipalName' => 'service@acme.com',
                'displayName' => 'Service Account',
                'accountEnabled' => true,
                'licensed' => false,
                'mailboxPurpose' => 'user',
            ],
        ]);

        $result = app(EntraGroupSyncService::class)->syncClient($client);

        $this->assertSame(0, $result->created);
        $this->assertDatabaseMissing('users', ['email' => 'service@acme.com']);
    }

    public function test_sync_deactivates_users_removed_from_scope(): void
    {
        $tenantId = '11111111-1111-1111-1111-111111111111';

        $client = Client::factory()->create([
            'entra_tenant_id' => $tenantId,
            'entra_sync_enabled' => true,
        ]);

        User::factory()->create([
            'client_id' => $client->id,
            'email' => 'gone@acme.com',
            'role' => UserRole::ClientUser,
            'provisioned_by' => UserProvisionSource::EntraSync,
            'entra_object_id' => 'cccccccc-cccc-cccc-cccc-cccccccccccc',
            'is_active' => true,
            'portal_login_enabled' => true,
        ]);

        $this->fakeTenantSyncGraph($tenantId, []);

        $result = app(EntraGroupSyncService::class)->syncClient($client);

        $this->assertSame(1, $result->deactivated);
        $this->assertDatabaseHas('users', [
            'email' => 'gone@acme.com',
            'is_active' => false,
            'portal_login_enabled' => false,
        ]);
    }

    public function test_sync_deactivates_disabled_entra_accounts(): void
    {
        $tenantId = '11111111-1111-1111-1111-111111111111';

        $client = Client::factory()->create([
            'entra_tenant_id' => $tenantId,
            'entra_sync_enabled' => true,
        ]);

        $this->fakeTenantSyncGraph($tenantId, [
            'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa' => [
                'mail' => 'disabled@acme.com',
                'userPrincipalName' => 'disabled@acme.com',
                'displayName' => 'Disabled User',
                'accountEnabled' => false,
                'licensed' => true,
                'mailboxPurpose' => 'user',
            ],
        ]);

        app(EntraGroupSyncService::class)->syncClient($client);

        $this->assertDatabaseHas('users', [
            'email' => 'disabled@acme.com',
            'name' => 'Disabled User (User)',
            'is_active' => false,
            'portal_login_enabled' => false,
        ]);
    }

    public function test_manual_users_are_not_deactivated_by_sync(): void
    {
        $tenantId = '11111111-1111-1111-1111-111111111111';

        $client = Client::factory()->create([
            'entra_tenant_id' => $tenantId,
            'entra_sync_enabled' => true,
        ]);

        User::factory()->create([
            'client_id' => $client->id,
            'email' => 'manual@acme.com',
            'role' => UserRole::ClientUser,
            'provisioned_by' => UserProvisionSource::Manual,
            'is_active' => true,
        ]);

        $this->fakeTenantSyncGraph($tenantId, []);

        app(EntraGroupSyncService::class)->syncClient($client);

        $this->assertDatabaseHas('users', [
            'email' => 'manual@acme.com',
            'is_active' => true,
        ]);
    }

    public function test_display_name_formatter_strips_existing_suffix(): void
    {
        $this->assertSame(
            'Jane Smith (Shared Mailbox)',
            EntraSyncDisplayName::format('Jane Smith (User)', EntraIdentityType::SharedMailbox),
        );
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
}
