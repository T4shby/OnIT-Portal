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

    public function test_refresh_fetches_and_caches_dropsuite_backup_summary(): void
    {
        Http::fake([
            'https://dropsuite.us/api/organizations/org-123/backup-summary/' => Http::response([
                'protected_mailboxes' => 42,
                'failed_backups_count' => 2,
            ], 200),
        ]);

        $client = Client::factory()->create(['dropsuite_organization_id' => 'org-123']);

        $summary = app(DropsuiteClientMetricsService::class)->refreshAndStore($client);

        $this->assertTrue($summary->available);
        $this->assertSame(42, $summary->protectedMailboxes);
        $this->assertSame(2, $summary->failedBackupsCount);
        $this->assertSame('warning', $summary->lastBackupStatus);
        $this->assertNotNull($summary->lastRefreshedAt);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://dropsuite.us/api/organizations/org-123/backup-summary/'
                && $request->hasHeader('Authorization', 'Token auth-test-token')
                && $request->hasHeader('X-Reseller-Token', 'reseller-test-token');
        });
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
