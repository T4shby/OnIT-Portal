<?php

namespace Tests\Unit;

use App\Enums\UserRole;
use App\Models\Client;
use App\Models\User;
use App\Services\Dropsuite\DropsuiteClientMetricsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
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

    public function test_refresh_uses_pdf_accounts_endpoint_and_filters_by_organization(): void
    {
        Http::fake([
            'https://dropsuite.us/api/users*' => Http::response([
                'result_set' => [
                    [
                        'id' => '1',
                        'email' => 'admin@customer.test',
                        'organization_id' => 19771,
                        'organization_name' => 'Customer',
                        'authentication_token' => 'user-org-token',
                        'admin' => true,
                    ],
                ],
                'pagination' => ['current_page' => 1, 'total_pages' => 1],
            ], 200),
            'https://dropsuite.us/api/accounts*' => Http::response([
                'result_set' => [
                    [
                        'id' => 5238,
                        'last_backup' => '2023-05-11T06:43:27.040Z',
                        'email' => 'alexw@twx4l.onmicrosoft.com',
                        'display_name' => 'Alex',
                        'errors' => [],
                        'current_backup_status' => 'Running',
                        'user' => ['organization_id' => 19771],
                    ],
                    [
                        'id' => 5240,
                        'last_backup' => null,
                        'email' => 'broken@twx4l.onmicrosoft.com',
                        'errors' => ['host' => 'timeout'],
                        'current_backup_status' => 'Preparing Backup',
                        'user' => ['organization_id' => 19771],
                    ],
                ],
                'pagination' => ['current_page' => 1, 'total_pages' => 1, 'total_entries' => 2],
            ], 200),
            'https://dropsuite.us/api/onedrives*' => Http::response([
                'result_set' => [
                    ['email' => 'alexw@twx4l.onmicrosoft.com'],
                ],
            ], 200),
            'https://dropsuite.us/api/sharepoints*' => Http::response(['result_set' => []], 200),
            'https://dropsuite.us/api/sites*' => Http::response(['result_set' => []], 200),
        ]);

        $client = Client::factory()->create(['dropsuite_organization_id' => '19771']);

        $summary = app(DropsuiteClientMetricsService::class)->refreshAndStore($client);

        $this->assertTrue($summary->available);
        $this->assertSame(2, $summary->protectedMailboxes);
        $this->assertSame(1, $summary->failedBackupsCount);
        $this->assertSame('warning', $summary->lastBackupStatus);
        $this->assertSame(1, $summary->onedriveCount);
        $this->assertNotNull($summary->lastBackupAt);
        $this->assertCount(2, $summary->accounts);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/users')
                && $request->hasHeader('X-Access-Token', 'auth-test-token')
                && $request->hasHeader('X-Reseller-Token', 'reseller-test-token');
        });
        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/accounts')
                && $request->hasHeader('X-Access-Token', 'user-org-token');
        });
    }

    public function test_requester_sees_only_personal_last_backup(): void
    {
        $client = Client::factory()->create(['dropsuite_organization_id' => '19771']);
        $requester = User::factory()->create([
            'client_id' => $client->id,
            'role' => UserRole::ClientRequester,
            'email' => 'alexw@twx4l.onmicrosoft.com',
        ]);

        cache()->put(app(DropsuiteClientMetricsService::class)->cacheKey($client->id), [
            'protected_mailboxes' => 2,
            'failed_backups_count' => 1,
            'last_backup_status' => 'warning',
            'last_backup_at' => '2023-05-11T06:43:27.040Z',
            'onedrive_count' => 1,
            'accounts' => [
                [
                    'email' => 'alexw@twx4l.onmicrosoft.com',
                    'display_name' => 'Alex',
                    'last_backup_at' => '2023-05-11T06:43:27.040Z',
                    'current_backup_status' => 'Running',
                    'has_errors' => false,
                ],
                [
                    'email' => 'broken@twx4l.onmicrosoft.com',
                    'display_name' => null,
                    'last_backup_at' => null,
                    'current_backup_status' => 'Failed',
                    'has_errors' => true,
                ],
            ],
            'last_refreshed_at' => now()->toIso8601String(),
        ], now()->addHour());

        $summary = app(DropsuiteClientMetricsService::class)->summaryForClient($client, false, $requester);

        $this->assertTrue($summary->isPersonal());
        $this->assertSame('alexw@twx4l.onmicrosoft.com', $summary->personalEmail);
        $this->assertNotNull($summary->lastBackupAt);
        $this->assertSame('success', $summary->lastBackupStatus);
        $this->assertCount(1, $summary->accounts);
    }

    public function test_client_admin_sees_organisation_wide_accounts(): void
    {
        $client = Client::factory()->create(['dropsuite_organization_id' => '19771']);
        $admin = User::factory()->create([
            'client_id' => $client->id,
            'role' => UserRole::ClientAdmin,
            'email' => 'admin@twx4l.onmicrosoft.com',
        ]);

        cache()->put(app(DropsuiteClientMetricsService::class)->cacheKey($client->id), [
            'protected_mailboxes' => 2,
            'failed_backups_count' => 1,
            'last_backup_status' => 'warning',
            'last_backup_at' => '2023-05-11T06:43:27.040Z',
            'onedrive_count' => 0,
            'accounts' => [
                [
                    'email' => 'alexw@twx4l.onmicrosoft.com',
                    'last_backup_at' => '2023-05-11T06:43:27.040Z',
                    'current_backup_status' => 'Running',
                    'has_errors' => false,
                ],
                [
                    'email' => 'broken@twx4l.onmicrosoft.com',
                    'last_backup_at' => null,
                    'current_backup_status' => 'Failed',
                    'has_errors' => true,
                ],
            ],
            'last_refreshed_at' => now()->toIso8601String(),
        ], now()->addHour());

        $summary = app(DropsuiteClientMetricsService::class)->summaryForClient($client, false, $admin);

        $this->assertFalse($summary->isPersonal());
        $this->assertSame(2, $summary->protectedMailboxes);
        $this->assertSame(1, $summary->failedBackupsCount);
        $this->assertCount(2, $summary->accounts);
    }

    public function test_summary_is_unavailable_when_client_is_not_linked(): void
    {
        Http::fake();

        $client = Client::factory()->create([
            'dropsuite_organization_id' => null,
            'product_entitlements' => [
                'dropsuite' => ['entitled' => true],
            ],
        ]);

        $summary = app(DropsuiteClientMetricsService::class)->summaryForClient($client);

        $this->assertFalse($summary->available);
        $this->assertSame('Dropsuite is not connected for this organisation.', $summary->unavailableReason);
        Http::assertNothingSent();
    }

    public function test_refresh_throws_when_org_has_no_user_token_and_no_cache(): void
    {
        Http::fake([
            'https://dropsuite.us/api/users*' => Http::response([
                'result_set' => [
                    [
                        'id' => '1',
                        'email' => 'admin@other.test',
                        'organization_id' => 999,
                        'authentication_token' => 'other-token',
                        'admin' => true,
                    ],
                ],
                'pagination' => ['current_page' => 1, 'total_pages' => 1],
            ], 200),
        ]);

        $client = Client::factory()->create(['dropsuite_organization_id' => '177210-12']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('no user access token for organization 177210-12');

        app(DropsuiteClientMetricsService::class)->refreshAndStore($client);
    }

    public function test_org_user_tokens_are_not_cached_in_plaintext(): void
    {
        // Production uses CACHE_STORE=database: assert on what lands in that table.
        config(['cache.default' => 'database']);
        \Illuminate\Support\Facades\Cache::forgetDriver('database');

        Http::fake([
            'https://dropsuite.us/api/users*' => Http::response([
                'result_set' => [
                    ['id' => '1', 'email' => 'admin@other.test', 'organization_id' => 999, 'authentication_token' => 'other-org-secret-token', 'admin' => true],
                    ['id' => '2', 'email' => 'admin@acme.test', 'organization_id' => 6182, 'authentication_token' => 'acme-secret-token', 'admin' => true],
                ],
                'pagination' => ['current_page' => 1, 'total_pages' => 1],
            ], 200),
            'https://dropsuite.us/api/*' => Http::response(['result_set' => [], 'pagination' => ['current_page' => 1, 'total_pages' => 1]], 200),
        ]);

        $client = Client::factory()->create(['dropsuite_organization_id' => '6182']);

        try {
            app(DropsuiteClientMetricsService::class)->refreshAndStore($client);
        } catch (\Throwable) {
            // Only the token cache matters here.
        }

        Http::assertSent(fn ($request) => str_contains($request->url(), '/accounts')
            && $request->hasHeader('X-Access-Token', 'acme-secret-token'));

        $this->assertNull(Cache::get('dropsuite.users.list.v1'));
        $raw = \Illuminate\Support\Facades\DB::table('cache')->pluck('value')->implode(' ');
        $this->assertStringNotContainsString('acme-secret-token', $raw);
        $this->assertStringNotContainsString('other-org-secret-token', $raw);
        $this->assertStringNotContainsString('admin@other.test', $raw);
    }
}
