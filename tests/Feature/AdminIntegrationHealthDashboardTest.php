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

    public function test_integration_health_tab_shows_pipeline_for_clients(): void
    {
        $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
        $client = Client::factory()->create([
            'name' => 'Acme Ltd',
            'is_active' => true,
            'superops_account_id' => 'acc-1',
            'entra_tenant_id' => '11111111-1111-1111-1111-111111111111',
        ]);

        Cache::put("client:{$client->id}:superops-dashboard:v4", [
            'assets_total' => 3,
            'last_refreshed_at' => now()->subMinutes(12)->toIso8601String(),
        ], now()->addDay());

        Cache::put('m365_directory.meta.'.$client->id, [
            'refreshed_at' => now()->subMinutes(40)->toIso8601String(),
        ], now()->addDay());

        Cache::put('m365_directory.refresh_queued.'.$client->id, true, now()->addMinutes(10));
        Cache::put('m365_directory.refresh_started.'.$client->id, now()->subMinutes(12)->toIso8601String(), now()->addMinutes(30));

        $response = $this->actingAs($admin)->get(route('admin.integration-health.index'));

        $response->assertOk();
        $response->assertSee('Integration Health', false);
        $response->assertSee('What is going on', false);
        $response->assertSee('Action list', false);
        $response->assertSee('Per client', false);
        $response->assertSee('Acme Ltd', false);
        $response->assertSee('Stuck', false);
        $response->assertSee('Timing settings:', false);
        // Labels go through Blade e() so & becomes &amp; - assertSeeText decodes.
        $response->assertSeeText('Devices & tickets');
        $response->assertSeeText('Huntress');
        $response->assertSeeText('Dropsuite');
    }

    public function test_whats_going_on_summary_mirrors_saved_timing_settings(): void
    {
        $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);

        \App\Models\Setting::set('freshness.hot_minutes', '3.5');
        \App\Models\Setting::set('freshness.work_idle_minutes', '45');
        \App\Models\Setting::set('freshness.off_hours_idle_minutes', '90');
        \App\Models\Setting::set('freshness.presence_minutes', '12');
        \App\Models\Setting::set('freshness.work_start', '08:00');
        \App\Models\Setting::set('freshness.work_end', '18:00');
        \App\Models\Setting::set('freshness.timezone', 'Europe/London');

        $response = $this->actingAs($admin)->get(route('admin.integration-health.index'));

        $response->assertOk();
        $response->assertSee('Timing settings:', false);
        $response->assertSee('Fast 3.5m', false);
        $response->assertSee('Idle business 45m', false);
        $response->assertSee('Idle outside 90m', false);
        $response->assertSee('Active session 12m', false);
        $response->assertSee('Hours 08:00-18:00', false);
        $response->assertSee('Europe/London', false);
        $response->assertSee('value="3.5"', false);
        $response->assertSee('value="45"', false);
        $response->assertSee('value="90"', false);
        $response->assertSee('value="08:00"', false);
    }

    public function test_saving_timing_settings_updates_summary_values(): void
    {
        $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);

        $this->actingAs($admin)
            ->put(route('admin.integration-health.freshness.update'), [
                'freshness' => [
                    'hot_minutes' => '4',
                    'work_idle_minutes' => '55',
                    'off_hours_idle_minutes' => '75',
                    'presence_minutes' => '20',
                    'work_start' => '6:30',
                    'work_end' => '17:00',
                    'timezone' => 'Europe/London',
                ],
            ])
            ->assertRedirect(route('admin.integration-health.index'));

        $response = $this->actingAs($admin)->get(route('admin.integration-health.index'));

        $response->assertOk();
        $response->assertSee('Fast 4m', false);
        $response->assertSee('Idle business 55m', false);
        $response->assertSee('Idle outside 75m', false);
        $response->assertSee('Active session 20m', false);
        $response->assertSee('Hours 06:30-17:00', false);
    }

    public function test_admin_dashboard_links_to_integration_health_tab(): void
    {
        $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);

        $response = $this->actingAs($admin)->get(route('admin.dashboard'));

        $response->assertOk();
        $response->assertSee('Open Integration Health', false);
        $response->assertSee(route('admin.integration-health.index'), false);
        $response->assertDontSee('Integration refresh health', false);
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

        Cache::put('entra_sync.refresh_queued.'.$client->id, true, now()->addMinutes(10));
        Cache::put('entra_sync.refresh_started.'.$client->id, now()->toIso8601String(), now()->addMinutes(10));

        $response = $this->actingAs($admin)->get(route('admin.integration-health.live'));

        $response->assertOk();
        $response->assertSee('Find', false);
        $response->assertSee('Refreshing now', false);
        $response->assertSee('Entra sync', false);
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
            ->get(route('admin.integration-health.index'))
            ->assertForbidden();

        $this->actingAs($user)
            ->get(route('admin.integration-health.live'))
            ->assertForbidden();
    }
}
