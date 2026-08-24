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

    public function test_dashboard_shows_beta_banner(): void
    {
        $client = Client::factory()->create();
        $admin = User::factory()->create([
            'client_id' => $client->id,
            'role' => UserRole::ClientAdmin,
        ]);

        $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('still in beta', false)
            ->assertSee(route('support.create'), false);
    }

    /**
     * @return array{0: Client, 1: User}
     */
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
