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
        $groupId = '22222222-2222-2222-2222-222222222222';

        $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
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
        $groupId = '22222222-2222-2222-2222-222222222222';

        $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
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
        ]);

        $response = $this->actingAs($admin)
            ->post(route('admin.clients.sync-entra', $client));

        $response->assertRedirect();
        $response->assertSessionHas('success');
        $this->assertDatabaseHas('users', [
            'email' => 'jane@acme.com',
            'client_id' => $client->id,
            'provisioned_by' => UserProvisionSource::EntraSync->value,
        ]);
    }

    public function test_client_user_cannot_run_entra_sync(): void
    {
        $client = Client::factory()->create([
            'entra_tenant_id' => '11111111-1111-1111-1111-111111111111',
            'entra_group_id' => '22222222-2222-2222-2222-222222222222',
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
            'entra_group_id' => '22222222-2222-2222-2222-222222222222',
            'entra_sync_enabled' => true,
        ]);

        $response = $this->actingAs($admin)
            ->post(route('admin.clients.sync-entra', $client));

        $response->assertRedirect();
        $response->assertSessionHas('error');
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

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $client->refresh();
        $this->assertTrue($client->onboarding_checklist['entra_group_created']);
        $this->assertTrue($client->onboarding_checklist['superops_scim_configured']);
        $this->assertFalse($client->onboarding_checklist['handed_off']);
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
