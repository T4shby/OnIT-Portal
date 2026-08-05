<?php

namespace Tests\Unit;

use App\Models\Client;
use App\Services\Dropsuite\DropsuiteClientMetricsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DropsuiteClientMetricsServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.dropsuite.enabled' => true,
            'services.dropsuite.api_url' => 'https://dropsuite.us/api',
            'services.dropsuite.reseller_token' => 'reseller-test-token',
            'services.dropsuite.auth_token' => 'auth-test-token',
        ]);
    }

    public function test_refresh_fetches_account_detail_with_dual_auth_headers(): void
    {
        Http::fake(function (\Illuminate\Http\Client\Request $request) {
            if (str_contains($request->url(), '/accounts/org-123')) {
                return Http::response([
                    'id' => 'org-123',
                    'protected_mailboxes' => 42,
                    'failed_backups_count' => 2,
                ], 200);
            }

            return Http::response(['detail' => 'not found'], 404);
        });

        $client = Client::factory()->create(['dropsuite_organization_id' => 'org-123']);

        $summary = app(DropsuiteClientMetricsService::class)->refreshAndStore($client);

        $this->assertTrue($summary->available);
        $this->assertSame(42, $summary->protectedMailboxes);
        $this->assertSame(2, $summary->failedBackupsCount);
        $this->assertSame('warning', $summary->lastBackupStatus);
        $this->assertNotNull($summary->lastRefreshedAt);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/accounts/org-123')
                && $request->hasHeader('X-Access-Token', 'auth-test-token')
                && $request->hasHeader('X-Reseller-Token', 'reseller-test-token')
                && $request->hasHeader('Authorization', 'Token auth-test-token');
        });
    }

    public function test_map_payload_accepts_nested_data_keys(): void
    {
        $mapped = app(DropsuiteClientMetricsService::class)->mapPayload([
            'data' => [
                'mailbox_count' => 9,
                'status' => 'ok',
            ],
        ], 'org-1');

        $this->assertSame(9, $mapped['protected_mailboxes']);
        $this->assertSame('success', $mapped['last_backup_status']);
    }

    public function test_summary_is_unavailable_when_client_is_not_linked(): void
    {
        Http::fake();

        $client = Client::factory()->create(['dropsuite_organization_id' => null]);

        $summary = app(DropsuiteClientMetricsService::class)->summaryForClient($client);

        $this->assertFalse($summary->available);
        $this->assertSame('Dropsuite is not connected for this organisation.', $summary->unavailableReason);
        Http::assertNothingSent();
    }
}
