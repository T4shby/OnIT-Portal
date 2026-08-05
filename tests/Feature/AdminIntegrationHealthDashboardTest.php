<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Jobs\SyncEntraClientJob;
use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AdminIntegrationHealthDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_dashboard_shows_integration_health_for_clients(): void
    {
        $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
        $client = Client::factory()->create([
            'name' => 'Acme Ltd',
            'is_active' => true,
            'superops_account_id' => 'acc-1',
            'entra_tenant_id' => '11111111-1111-1111-1111-111111111111',
        ]);

        Cache::put("client:{$client->id}:superops-dashboard:v2", [
            'assets_total' => 3,
            'last_refreshed_at' => now()->subMinutes(12)->toIso8601String(),
        ], now()->addDay());

        Cache::put('m365_directory.meta.'.$client->id, [
            'refreshed_at' => now()->subMinutes(40)->toIso8601String(),
        ], now()->addDay());

        Cache::put('m365_directory.refresh_queued.'.$client->id, true, now()->addMinutes(10));
        Cache::put('m365_directory.refresh_started.'.$client->id, now()->subMinutes(12)->toIso8601String(), now()->addMinutes(30));

        $response = $this->actingAs($admin)->get(route('admin.dashboard'));

        $response->assertOk();
        $response->assertSee('Integration refresh health', false);
        $response->assertSee('Live · 5s', false);
        $response->assertSee('Why isn\'t it resetting?', false);
        $response->assertSee('Prewarm heartbeat', false);
        $response->assertSee('Acme Ltd', false);
        $response->assertSee('stuck', false);
        $response->assertSee('M365 directory', false);
        $response->assertSee('flag=', false);
    }

    public function test_integration_health_fragment_endpoint_polls_for_admins(): void
    {
        $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
        $client = Client::factory()->create([
            'name' => 'Find',
            'is_active' => true,
            'entra_sync_enabled' => true,
            'entra_tenant_id' => '11111111-1111-1111-1111-111111111111',
            'entra_synced_at' => now()->subHour(),
        ]);

        // Queued + started = running (not an orphan)
        Cache::put('entra_sync.refresh_queued.'.$client->id, true, now()->addMinutes(10));
        Cache::put('entra_sync.refresh_started.'.$client->id, now()->toIso8601String(), now()->addMinutes(10));

        $response = $this->actingAs($admin)->get(route('admin.integration-health'));

        $response->assertOk();
        $response->assertSee('Find', false);
        $response->assertSee('running', false);
        $response->assertSee('Entra portal sync', false);
    }

    public function test_entra_dispatch_marked_sets_queued_flag_immediately(): void
    {
        Queue::fake();

        $client = Client::factory()->create(['is_active' => true]);

        SyncEntraClientJob::dispatchMarked($client->id);

        $this->assertTrue(Cache::has('entra_sync.refresh_queued.'.$client->id));
        Queue::assertPushed(SyncEntraClientJob::class);
    }

    public function test_client_admin_cannot_see_technician_integration_health(): void
    {
        $client = Client::factory()->create(['is_active' => true]);
        $user = User::factory()->create([
            'role' => UserRole::ClientAdmin,
            'client_id' => $client->id,
        ]);

        $this->actingAs($user)
            ->get(route('admin.dashboard'))
            ->assertForbidden();

        $this->actingAs($user)
            ->get(route('admin.integration-health'))
            ->assertForbidden();
    }
}
