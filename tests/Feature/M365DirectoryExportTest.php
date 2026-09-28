<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Client;
use App\Models\User;
use App\Services\M365\M365DirectorySnapshot;
use App\Services\M365\M365InsightsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class M365DirectoryExportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // M365 directory is gated behind entra_sync platform readiness (Graph app credentials).
        config([
            'services.entra_sync.client_id' => 'entra-sync-test-client-id',
            'services.entra_sync.client_secret' => 'entra-sync-test-client-secret',
        ]);
    }

    public function test_client_admin_can_download_csv_with_licences_then_users(): void
    {
        [$client, $admin] = $this->seedClientAdminWithDirectory();

        $response = $this->actingAs($admin)
            ->get(route('microsoft-365.directory.export', ['format' => 'csv']));

        $response->assertOk();
        $response->assertHeader('content-disposition');
        $csv = $response->streamedContent();

        $this->assertStringContainsString('Licences', $csv);
        $this->assertStringContainsString('Users', $csv);
        $this->assertStringContainsString('Microsoft 365 Business Premium', $csv);
        $this->assertStringContainsString('Jane Smith', $csv);
        $this->assertStringContainsString('jane@acme.com', $csv);
        $this->assertTrue(
            strpos($csv, 'Licences') < strpos($csv, 'Jane Smith'),
            'Licences section should appear before user rows',
        );
    }

    public function test_client_admin_can_download_xlsx(): void
    {
        [$client, $admin] = $this->seedClientAdminWithDirectory();

        $response = $this->actingAs($admin)
            ->get(route('microsoft-365.directory.export', ['format' => 'xlsx']));

        $response->assertOk();
        $body = $response->streamedContent();
        $this->assertSame('PK', substr($body, 0, 2));
        $this->assertStringContainsString(
            'spreadsheetml.sheet',
            (string) $response->headers->get('content-type'),
        );
    }

    public function test_dashboard_renders_with_support_link_for_client_admin(): void
    {
        $client = Client::factory()->create();
        $admin = User::factory()->create([
            'client_id' => $client->id,
            'role' => UserRole::ClientAdmin,
        ]);

        // "still in beta" banner copy and the direct Support link were removed
        // from the dashboard views; this now only checks the glance dashboard
        // renders successfully for a Client Admin.
        $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertOk();
    }

    /**
     * @return array{0: Client, 1: User}
     */
    public function test_personal_viewer_cannot_download_organisation_licence_export(): void
    {
        [$client] = $this->seedClientAdminWithDirectory();

        foreach ([UserRole::ClientRequester, UserRole::ClientBillingAdmin] as $role) {
            $viewer = User::factory()->create([
                'client_id' => $client->id,
                'role' => $role,
                'email' => $role->value.'@acme.com',
            ]);

            foreach (['csv', 'xlsx'] as $format) {
                $this->actingAs($viewer)
                    ->get(route('microsoft-365.directory.export', ['format' => $format]))
                    ->assertForbidden();
            }
        }
    }

    private function seedClientAdminWithDirectory(): array
    {
        $client = Client::factory()->create([
            'name' => 'Acme Ltd',
            'entra_tenant_id' => '11111111-1111-1111-1111-111111111111',
            'entra_sync_enabled' => true,
        ]);

        $admin = User::factory()->create([
            'client_id' => $client->id,
            'role' => UserRole::ClientAdmin,
        ]);

        Cache::put('m365_directory.client.'.$client->id, new M365DirectorySnapshot(
            collect([
                [
                    'displayName' => 'Jane Smith',
                    'email' => 'jane@acme.com',
                    'type' => 'user',
                    'typeLabel' => 'User mailbox',
                    'accountEnabled' => true,
                    'licenses' => ['O365_BUSINESS_PREMIUM'],
                    'portalLogin' => true,
                ],
            ]),
            collect(),
            now(),
        ), now()->addHour());

        Cache::put('m365_directory.meta.'.$client->id, [
            'last_refreshed_at' => now()->toIso8601String(),
        ], now()->addHour());

        Cache::put(app(M365InsightsService::class)->cacheKey($client->id), [
            'licensed_user_count' => 1,
            'total_seats_purchased' => 10,
            'total_seats_assigned' => 8,
            'overall_utilization_pct' => 80.0,
            'top_skus' => [
                [
                    'skuPartNumber' => 'O365_BUSINESS_PREMIUM',
                    'displayName' => 'Microsoft 365 Business Premium',
                    'purchased' => 10,
                    'assigned' => 8,
                    'utilizationPct' => 80.0,
                    'countsTowardUtilisation' => true,
                ],
            ],
            'all_skus' => [
                [
                    'skuPartNumber' => 'O365_BUSINESS_PREMIUM',
                    'displayName' => 'Microsoft 365 Business Premium',
                    'purchased' => 10,
                    'assigned' => 8,
                    'utilizationPct' => 80.0,
                    'countsTowardUtilisation' => true,
                ],
            ],
            'last_refreshed_at' => now()->toIso8601String(),
        ], now()->addDay());

        return [$client, $admin];
    }
}
