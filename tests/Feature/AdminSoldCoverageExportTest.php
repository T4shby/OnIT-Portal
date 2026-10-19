<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Client;
use App\Models\User;
use App\Services\Admin\IntegrationHealthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class AdminSoldCoverageExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_filter_and_export_coverage_by_account_manager(): void
    {
        $this->primeHealth();

        $mine = Client::factory()->create([
            'name' => 'AM Client Alpha',
            'is_active' => true,
            'superops_account_id' => '111',
            'product_entitlements' => [
                'superops' => ['entitled' => true],
                'dropsuite' => ['entitled' => false],
                'm365' => ['entitled' => false],
                'huntress' => ['entitled' => false],
            ],
        ]);
        $other = Client::factory()->create([
            'name' => 'Unowned Client Beta',
            'is_active' => true,
            'superops_account_id' => '222',
            'product_entitlements' => [
                'superops' => ['entitled' => true],
                'dropsuite' => ['entitled' => false],
                'm365' => ['entitled' => false],
                'huntress' => ['entitled' => false],
            ],
        ]);

        Cache::put(app(\App\Services\SuperOps\SuperOpsClientMetricsService::class)->cacheKey($mine->id), [
            'assets_total' => 1,
            'last_refreshed_at' => now()->subMinute()->toIso8601String(),
        ], now()->addHour());
        Cache::put(app(\App\Services\SuperOps\SuperOpsClientMetricsService::class)->cacheKey($other->id), [
            'assets_total' => 1,
            'last_refreshed_at' => now()->subMinute()->toIso8601String(),
        ], now()->addHour());

        $manager = User::factory()->create([
            'role' => UserRole::AccountManager,
            'name' => 'Pat Manager',
            'is_active' => true,
        ]);
        $manager->assignedClients()->attach($mine);

        $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);

        $this->actingAs($admin)->get(route('admin.dashboard', ['account_manager' => $manager->id]))
            ->assertOk()
            ->assertSee('AM Client Alpha')
            ->assertSee('Pat Manager')
            ->assertDontSee('Unowned Client Beta')
            ->assertSee('Export CSV');

        $this->actingAs($admin)->get(route('admin.dashboard', ['account_manager' => 'none']))
            ->assertOk()
            ->assertSee('Unowned Client Beta')
            ->assertDontSee('AM Client Alpha');

        $csv = $this->actingAs($admin)
            ->get(route('admin.dashboard.coverage-export', ['account_manager' => $manager->id]))
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8')
            ->streamedContent();

        $this->assertStringContainsString('AM Client Alpha', $csv);
        $this->assertStringContainsString('Pat Manager', $csv);
        $this->assertStringNotContainsString('Unowned Client Beta', $csv);
    }

    public function test_account_manager_export_stays_on_assigned_clients_only(): void
    {
        $this->primeHealth();

        $mine = Client::factory()->create([
            'name' => 'Mine Only Ltd',
            'is_active' => true,
            'superops_account_id' => '333',
            'product_entitlements' => [
                'superops' => ['entitled' => true],
                'dropsuite' => ['entitled' => false],
                'm365' => ['entitled' => false],
                'huntress' => ['entitled' => false],
            ],
        ]);
        $foreign = Client::factory()->create([
            'name' => 'Foreign Only Ltd',
            'is_active' => true,
            'superops_account_id' => '444',
            'product_entitlements' => [
                'superops' => ['entitled' => true],
                'dropsuite' => ['entitled' => false],
                'm365' => ['entitled' => false],
                'huntress' => ['entitled' => false],
            ],
        ]);

        Cache::put(app(\App\Services\SuperOps\SuperOpsClientMetricsService::class)->cacheKey($mine->id), [
            'assets_total' => 1,
            'last_refreshed_at' => now()->subMinute()->toIso8601String(),
        ], now()->addHour());
        Cache::put(app(\App\Services\SuperOps\SuperOpsClientMetricsService::class)->cacheKey($foreign->id), [
            'assets_total' => 1,
            'last_refreshed_at' => now()->subMinute()->toIso8601String(),
        ], now()->addHour());

        $manager = User::factory()->create(['role' => UserRole::AccountManager, 'is_active' => true]);
        $manager->assignedClients()->attach($mine);
        $otherManager = User::factory()->create(['role' => UserRole::AccountManager, 'is_active' => true]);
        $otherManager->assignedClients()->attach($foreign);

        $csv = $this->actingAs($manager)
            ->get(route('admin.dashboard.coverage-export', ['account_manager' => $otherManager->id]))
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('Mine Only Ltd', $csv);
        $this->assertStringNotContainsString('Foreign Only Ltd', $csv);
    }

    public function test_client_admin_cannot_export_coverage(): void
    {
        $client = Client::factory()->create();
        $user = User::factory()->create([
            'client_id' => $client->id,
            'role' => UserRole::ClientAdmin,
        ]);

        $this->actingAs($user)
            ->get(route('admin.dashboard.coverage-export'))
            ->assertForbidden();
    }

    private function primeHealth(): void
    {
        config([
            'services.superops.api_token' => 'token',
            'services.superops.subdomain' => 'onitltd',
        ]);

        Cache::put(IntegrationHealthService::SCHEDULER_TICK_KEY, now()->toIso8601String(), now()->addHour());
        Cache::put(IntegrationHealthService::PREWARM_CACHE_KEY, ['at' => now()->toIso8601String()], now()->addHour());
    }
}
