<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class M365DirectoryTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantId = '11111111-1111-1111-1111-111111111111';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.entra_sync.client_id' => 'test-client-id',
            'services.entra_sync.client_secret' => 'test-secret',
            'services.entra_sync.directory_cache_minutes' => 15,
        ]);
    }

    public function test_client_admin_can_view_directory(): void
    {
        $client = Client::factory()->create([
            'entra_tenant_id' => $this->tenantId,
            'entra_sync_enabled' => true,
        ]);

        $admin = User::factory()->create([
            'client_id' => $client->id,
            'role' => UserRole::ClientAdmin,
        ]);

        $this->fakeDirectoryGraph([
            'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa' => [
                'mail' => 'jane@acme.com',
                'userPrincipalName' => 'jane@acme.com',
                'displayName' => 'Jane Smith',
                'accountEnabled' => true,
                'licensed' => true,
                'mailboxPurpose' => 'user',
                'skus' => ['O365_BUSINESS_PREMIUM'],
            ],
        ], [
            [
                'id' => 'gggggggg-gggg-gggg-gggg-gggggggggggg',
                'displayName' => 'All Staff',
                'mail' => 'allstaff@acme.com',
                'mailEnabled' => true,
                'securityEnabled' => false,
                'groupTypes' => [],
                'description' => 'Company DL',
            ],
        ]);

        $directory = app(\App\Services\M365\M365DirectoryService::class);
        $directory->buildAndStoreSnapshot($client);

        Http::assertSent(fn ($request) => str_starts_with(
            $request->url(),
            'https://graph.microsoft.com/v1.0/subscribedSkus',
        ));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/licenseDetails'));

        $response = $this->actingAs($admin)->get(route('microsoft-365.directory'));

        $response->assertOk();
        $response->assertSee('Jane Smith');
        $response->assertDontSee('Jane Smith (User Mailbox)');
        $response->assertSee('Microsoft 365 Business Premium');
        $response->assertDontSee('>O365_BUSINESS_PREMIUM<', false);
        $response->assertSee('All Staff');
        $response->assertSee('Distribution list');
        $response->assertDontSee('Updating automatically every 8 seconds', false);
    }

    public function test_directory_live_fragment_returns_cached_tables(): void
    {
        $client = Client::factory()->create([
            'entra_tenant_id' => $this->tenantId,
            'entra_sync_enabled' => true,
        ]);

        $admin = User::factory()->create([
            'client_id' => $client->id,
            'role' => UserRole::ClientAdmin,
        ]);

        $this->fakeDirectoryGraph([
            'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa' => [
                'mail' => 'jane@acme.com',
                'userPrincipalName' => 'jane@acme.com',
                'displayName' => 'Jane Smith',
                'accountEnabled' => true,
                'licensed' => true,
                'mailboxPurpose' => 'user',
                'skus' => ['O365_BUSINESS_PREMIUM'],
            ],
        ], []);

        app(\App\Services\M365\M365DirectoryService::class)->buildAndStoreSnapshot($client);

        $this->actingAs($admin)
            ->get(route('microsoft-365.directory.live'))
            ->assertOk()
            ->assertSee('Jane Smith')
            ->assertDontSee('Jane Smith (User Mailbox)')
            ->assertSee('Microsoft 365 Business Premium')
            ->assertSee('m365-directory-live', false);
    }

    public function test_client_requester_sees_only_own_directory_person(): void
    {
        $client = Client::factory()->create([
            'entra_tenant_id' => $this->tenantId,
            'entra_sync_enabled' => true,
        ]);

        $user = User::factory()->create([
            'client_id' => $client->id,
            'role' => UserRole::ClientRequester,
            'email' => 'jane@acme.com',
            'name' => 'Jane Smith',
        ]);

        $this->fakeDirectoryGraph([
            'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa' => [
                'mail' => 'jane@acme.com',
                'userPrincipalName' => 'jane@acme.com',
                'displayName' => 'Jane Smith',
                'accountEnabled' => true,
                'licensed' => true,
                'mailboxPurpose' => 'user',
                'skus' => ['O365_BUSINESS_PREMIUM'],
            ],
            'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb' => [
                'mail' => 'bob@acme.com',
                'userPrincipalName' => 'bob@acme.com',
                'displayName' => 'Bob Other',
                'accountEnabled' => true,
                'licensed' => true,
                'mailboxPurpose' => 'user',
                'skus' => ['O365_BUSINESS_PREMIUM'],
            ],
        ], [
            [
                'id' => 'gggggggg-gggg-gggg-gggg-gggggggggggg',
                'displayName' => 'All Staff',
                'mail' => 'allstaff@acme.com',
                'mailEnabled' => true,
                'securityEnabled' => false,
                'groupTypes' => [],
                'description' => 'Company DL',
            ],
        ]);

        app(\App\Services\M365\M365DirectoryService::class)->buildAndStoreSnapshot($client);

        $this->actingAs($user)
            ->get(route('microsoft-365.directory'))
            ->assertOk()
            ->assertSee('Jane Smith')
            ->assertDontSee('Bob Other')
            ->assertDontSee('All Staff');
    }

    public function test_msp_admin_can_view_client_directory(): void
    {
        $client = Client::factory()->create(['entra_tenant_id' => $this->tenantId]);

        $msp = User::factory()->create(['role' => UserRole::SuperAdmin]);

        $this->fakeDirectoryGraph([], []);

        $this->actingAs($msp)
            ->get(route('admin.clients.microsoft-365', $client))
            ->assertOk()
            ->assertSee('Microsoft 365 Directory');
    }

    /**
     * @param  array<string, array<string, mixed>>  $usersById
     * @param  list<array<string, mixed>>  $groups
     */
    private function fakeDirectoryGraph(array $usersById, array $groups): void
    {
        $list = [];

        foreach ($usersById as $id => $user) {
            $list[] = [
                'id' => $id,
                'mail' => $user['mail'],
                'userPrincipalName' => $user['userPrincipalName'],
                'displayName' => $user['displayName'],
                'accountEnabled' => $user['accountEnabled'],
                'assignedLicenses' => array_map(
                    static fn (string $sku): array => ['skuId' => $sku],
                    $user['skus'] ?? [],
                ),
            ];
        }

        Http::fake(function ($request) use ($usersById, $list, $groups) {
            $url = $request->url();

            if ($url === "https://login.microsoftonline.com/{$this->tenantId}/oauth2/v2.0/token") {
                return Http::response(['access_token' => 'fake-token', 'expires_in' => 3600]);
            }

            if (str_starts_with($url, 'https://graph.microsoft.com/v1.0/users?')) {
                return Http::response(['value' => $list]);
            }

            if (str_starts_with($url, 'https://graph.microsoft.com/v1.0/groups')) {
                return Http::response(['value' => $groups]);
            }

            if (str_starts_with($url, 'https://graph.microsoft.com/v1.0/subscribedSkus')) {
                $skus = array_values(array_unique(array_reduce(
                    $usersById,
                    static fn (array $skus, array $user): array => array_merge($skus, $user['skus'] ?? []),
                    [],
                )));
                return Http::response([
                    'value' => array_map(
                        static fn (string $sku): array => ['skuId' => $sku, 'skuPartNumber' => $sku],
                        $skus,
                    ),
                ]);
            }

            if (preg_match('#/users/([0-9a-f-]+)/mailboxSettings#', $url, $matches)) {
                $userId = $matches[1];

                return Http::response([
                    'userPurpose' => $usersById[$userId]['mailboxPurpose'] ?? null,
                ]);
            }

            return Http::response([], 404);
        });
    }
}
