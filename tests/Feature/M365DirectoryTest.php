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
        $response->assertSee('Jane Smith (User Mailbox)');
        $response->assertSee('All Staff');
        $response->assertSee('Distribution list');
    }

    public function test_client_requester_cannot_view_directory(): void
    {
        $client = Client::factory()->create(['entra_tenant_id' => $this->tenantId]);

        $user = User::factory()->create([
            'client_id' => $client->id,
            'role' => UserRole::ClientRequester,
        ]);

        $this->actingAs($user)
            ->get(route('microsoft-365.directory'))
            ->assertForbidden();
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
