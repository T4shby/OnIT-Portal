<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Client;
use App\Models\User;
use App\Services\Dropsuite\DropsuiteClientMetricsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DropsuiteCustomerVisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.dropsuite.enabled' => true,
            'services.dropsuite.api_url' => 'https://dropsuite.us/api',
            'services.dropsuite.reseller_token' => 'r',
            'services.dropsuite.auth_token' => 'a',
            'services.superops.api_token' => null,
        ]);
    }

    public function test_client_admin_sees_backup_summary_not_full_list_on_overview(): void
    {
        $client = Client::factory()->create([
            'superops_account_id' => null,
            'dropsuite_organization_id' => '19771',
            'product_entitlements' => [
                'dropsuite' => ['entitled' => true],
                'superops' => ['entitled' => false],
                'huntress' => ['entitled' => false],
                'm365' => ['entitled' => false],
            ],
        ]);
        $admin = User::factory()->create([
            'client_id' => $client->id,
            'role' => UserRole::ClientAdmin,
            'email' => 'admin@customer.test',
        ]);

        $cacheKey = app(DropsuiteClientMetricsService::class)->cacheKey($client->id);
        cache()->put($cacheKey, [
            'protected_mailboxes' => 2,
            'failed_backups_count' => 0,
            'succeeded_last_24h' => 2,
            'failed_last_24h' => 0,
            'last_backup_status' => 'success',
            'last_backup_at' => '2026-08-07T10:00:00Z',
            'onedrive_count' => 1,
            'sharepoint_count' => 0,
            'accounts' => [
                [
                    'email' => 'alice@customer.test',
                    'display_name' => 'Alice',
                    'last_backup_at' => '2026-08-07T10:00:00Z',
                    'current_backup_status' => 'Success',
                    'has_errors' => false,
                ],
                [
                    'email' => 'bob@customer.test',
                    'display_name' => 'Bob',
                    'last_backup_at' => '2026-08-07T09:00:00Z',
                    'current_backup_status' => 'Success',
                    'has_errors' => false,
                ],
            ],
            'onedrives' => [],
            'sharepoints' => [],
            'last_refreshed_at' => now()->toIso8601String(),
        ], now()->addHour());

        $this->actingAs($admin)
            ->get(route('client-admin.dashboard'))
            ->assertOk()
            ->assertSee('Backups (Dropsuite)')
            ->assertSee('Protected mailboxes')
            ->assertSee('Succeeded in last 24 hours')
            ->assertSee('View all online backups')
            ->assertDontSee('alice@customer.test')
            ->assertDontSee('My backup');

        $this->actingAs($admin)
            ->get(route('client-admin.backups'))
            ->assertOk()
            ->assertSee('alice@customer.test')
            ->assertSee('bob@customer.test')
            ->assertSee('Online');
    }

    public function test_requester_sees_only_own_mailbox_last_backup(): void
    {
        $client = Client::factory()->create([
            'superops_account_id' => null,
            'dropsuite_organization_id' => '19771',
            'product_entitlements' => [
                'dropsuite' => ['entitled' => true],
                'superops' => ['entitled' => false],
                'huntress' => ['entitled' => false],
                'm365' => ['entitled' => false],
            ],
        ]);
        $requester = User::factory()->create([
            'client_id' => $client->id,
            'role' => UserRole::ClientRequester,
            'email' => 'alice@customer.test',
        ]);

        $cacheKey = app(DropsuiteClientMetricsService::class)->cacheKey($client->id);
        cache()->put($cacheKey, [
            'protected_mailboxes' => 2,
            'failed_backups_count' => 0,
            'last_backup_status' => 'success',
            'last_backup_at' => '2026-08-07T10:00:00Z',
            'onedrive_count' => 0,
            'accounts' => [
                [
                    'email' => 'alice@customer.test',
                    'display_name' => 'Alice',
                    'last_backup_at' => '2026-08-07T10:00:00Z',
                    'current_backup_status' => 'Success',
                    'has_errors' => false,
                ],
                [
                    'email' => 'bob@customer.test',
                    'display_name' => 'Bob',
                    'last_backup_at' => '2026-08-07T09:00:00Z',
                    'current_backup_status' => 'Success',
                    'has_errors' => false,
                ],
            ],
            'last_refreshed_at' => now()->toIso8601String(),
        ], now()->addHour());

        $this->actingAs($requester)
            ->get(route('client-admin.dashboard'))
            ->assertOk()
            ->assertSee('My backup')
            ->assertSee('alice@customer.test')
            ->assertSee('Last time')
            ->assertDontSee('bob@customer.test')
            ->assertDontSee('View all online backups');

        $this->actingAs($requester)
            ->get(route('client-admin.backups'))
            ->assertForbidden();
    }
}
