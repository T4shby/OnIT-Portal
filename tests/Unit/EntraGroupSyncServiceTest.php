<?php

namespace Tests\Unit;

use App\Enums\UserProvisionSource;
use App\Enums\UserRole;
use App\Models\Client;
use App\Models\User;
use App\Services\EntraSync\EntraGroupSyncService;
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

    public function test_sync_creates_users_from_entra_group(): void
    {
        $tenantId = '11111111-1111-1111-1111-111111111111';
        $groupId = '22222222-2222-2222-2222-222222222222';

        $client = Client::factory()->create([
            'entra_tenant_id' => $tenantId,
            'entra_group_id' => $groupId,
            'entra_sync_enabled' => true,
        ]);

        $this->fakeGraphResponses($tenantId, $groupId, [
            [
                'id' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa',
                'mail' => 'jane@acme.com',
                'userPrincipalName' => 'jane@acme.com',
                'displayName' => 'Jane Smith',
                'accountEnabled' => true,
            ],
            [
                'id' => 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb',
                'mail' => 'bob@acme.com',
                'userPrincipalName' => 'bob@acme.com',
                'displayName' => 'Bob Jones',
                'accountEnabled' => true,
            ],
        ]);

        $result = app(EntraGroupSyncService::class)->syncClient($client);

        $this->assertSame(2, $result->created);
        $this->assertDatabaseHas('users', [
            'email' => 'jane@acme.com',
            'client_id' => $client->id,
            'provisioned_by' => UserProvisionSource::EntraSync->value,
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('users', [
            'email' => 'bob@acme.com',
            'client_id' => $client->id,
        ]);
    }

    public function test_sync_deactivates_users_removed_from_group(): void
    {
        $tenantId = '11111111-1111-1111-1111-111111111111';
        $groupId = '22222222-2222-2222-2222-222222222222';

        $client = Client::factory()->create([
            'entra_tenant_id' => $tenantId,
            'entra_group_id' => $groupId,
            'entra_sync_enabled' => true,
        ]);

        User::factory()->create([
            'client_id' => $client->id,
            'email' => 'gone@acme.com',
            'role' => UserRole::ClientUser,
            'provisioned_by' => UserProvisionSource::EntraSync,
            'entra_object_id' => 'cccccccc-cccc-cccc-cccc-cccccccccccc',
            'is_active' => true,
        ]);

        $this->fakeGraphResponses($tenantId, $groupId, []);

        $result = app(EntraGroupSyncService::class)->syncClient($client);

        $this->assertSame(1, $result->deactivated);
        $this->assertDatabaseHas('users', [
            'email' => 'gone@acme.com',
            'is_active' => false,
        ]);
    }

    public function test_sync_deactivates_disabled_entra_accounts(): void
    {
        $tenantId = '11111111-1111-1111-1111-111111111111';
        $groupId = '22222222-2222-2222-2222-222222222222';

        $client = Client::factory()->create([
            'entra_tenant_id' => $tenantId,
            'entra_group_id' => $groupId,
            'entra_sync_enabled' => true,
        ]);

        $this->fakeGraphResponses($tenantId, $groupId, [
            [
                'id' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa',
                'mail' => 'disabled@acme.com',
                'userPrincipalName' => 'disabled@acme.com',
                'displayName' => 'Disabled User',
                'accountEnabled' => false,
            ],
        ]);

        app(EntraGroupSyncService::class)->syncClient($client);

        $this->assertDatabaseHas('users', [
            'email' => 'disabled@acme.com',
            'is_active' => false,
        ]);
    }

    public function test_manual_users_are_not_deactivated_by_sync(): void
    {
        $tenantId = '11111111-1111-1111-1111-111111111111';
        $groupId = '22222222-2222-2222-2222-222222222222';

        $client = Client::factory()->create([
            'entra_tenant_id' => $tenantId,
            'entra_group_id' => $groupId,
            'entra_sync_enabled' => true,
        ]);

        User::factory()->create([
            'client_id' => $client->id,
            'email' => 'manual@acme.com',
            'role' => UserRole::ClientUser,
            'provisioned_by' => UserProvisionSource::Manual,
            'is_active' => true,
        ]);

        $this->fakeGraphResponses($tenantId, $groupId, []);

        app(EntraGroupSyncService::class)->syncClient($client);

        $this->assertDatabaseHas('users', [
            'email' => 'manual@acme.com',
            'is_active' => true,
        ]);
    }

    public function test_manual_users_in_group_are_not_converted_by_sync(): void
    {
        $tenantId = '11111111-1111-1111-1111-111111111111';
        $groupId = '22222222-2222-2222-2222-222222222222';

        $client = Client::factory()->create([
            'entra_tenant_id' => $tenantId,
            'entra_group_id' => $groupId,
            'entra_sync_enabled' => true,
        ]);

        User::factory()->create([
            'client_id' => $client->id,
            'email' => 'manual@acme.com',
            'role' => UserRole::ClientUser,
            'provisioned_by' => UserProvisionSource::Manual,
            'is_active' => true,
        ]);

        $this->fakeGraphResponses($tenantId, $groupId, [
            [
                'id' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa',
                'mail' => 'manual@acme.com',
                'userPrincipalName' => 'manual@acme.com',
                'displayName' => 'Manual User',
                'accountEnabled' => true,
            ],
        ]);

        $result = app(EntraGroupSyncService::class)->syncClient($client);

        $this->assertSame(0, $result->created);
        $this->assertSame(0, $result->updated);
        $this->assertSame(1, $result->skipped);
        $this->assertDatabaseHas('users', [
            'email' => 'manual@acme.com',
            'provisioned_by' => UserProvisionSource::Manual->value,
        ]);
    }

    public function test_dry_run_reports_deactivations_without_changing_database(): void
    {
        $tenantId = '11111111-1111-1111-1111-111111111111';
        $groupId = '22222222-2222-2222-2222-222222222222';

        $client = Client::factory()->create([
            'entra_tenant_id' => $tenantId,
            'entra_group_id' => $groupId,
            'entra_sync_enabled' => true,
        ]);

        User::factory()->create([
            'client_id' => $client->id,
            'email' => 'gone@acme.com',
            'role' => UserRole::ClientUser,
            'provisioned_by' => UserProvisionSource::EntraSync,
            'entra_object_id' => 'cccccccc-cccc-cccc-cccc-cccccccccccc',
            'is_active' => true,
        ]);

        $this->fakeGraphResponses($tenantId, $groupId, []);

        $result = app(EntraGroupSyncService::class)->syncClient($client, dryRun: true);

        $this->assertSame(1, $result->deactivated);
        $this->assertDatabaseHas('users', [
            'email' => 'gone@acme.com',
            'is_active' => true,
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $users
     */
    private function fakeGraphResponses(string $tenantId, string $groupId, array $users): void
    {
        Http::fake([
            "https://login.microsoftonline.com/{$tenantId}/oauth2/v2.0/token" => Http::response([
                'access_token' => 'fake-token',
                'expires_in' => 3600,
            ]),
            "https://graph.microsoft.com/v1.0/groups/{$groupId}/transitiveMembers/microsoft.graph.user*" => Http::response([
                'value' => $users,
            ]),
        ]);
    }
}
