<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Jobs\RefreshM365DirectoryJob;
use App\Models\Client;
use App\Models\User;
use App\Services\M365\M365DirectorySnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ClientAdminDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_uses_cached_metrics_for_authenticated_client(): void
    {
        $client = Client::factory()->create(['superops_account_id' => '111']);
        $admin = User::factory()->create([
            'client_id' => $client->id,
            'role' => UserRole::ClientAdmin,
        ]);

        config(['services.superops.api_token' => 'token', 'services.superops.subdomain' => 'onitltd']);

        Cache::put("client:{$client->id}:superops-dashboard:v2", [
            'assets_total' => 14,
            'assets_online' => 12,
            'assets_offline' => 2,
            'open_tickets_total' => 6,
            'open_tickets_by_priority' => ['High' => 2, 'Normal' => 4],
            'open_tickets_table' => [
                ['displayId' => '1001', 'subject' => 'Test', 'priority' => 'High', 'status' => 'Open', 'createdTime' => now()->toIso8601String()],
            ],
            'sla_met_percent' => 95,
            'sla_sample_size' => 20,
            'tickets_created' => ['7' => 3, '14' => 7, '30' => 12, 'all' => 184],
            'tickets_closed' => ['7' => 2, '14' => 6, '30' => 10, 'all' => 171],
            'last_refreshed_at' => now()->toIso8601String(),
        ], now()->addHour());

        $response = $this->actingAs($admin)->get(route('client-admin.dashboard'));

        $response->assertOk();
        $response->assertSee('14');
        $response->assertSee('6');
        $response->assertSee('System health');
        $response->assertSee('Open tickets');
    }

    public function test_dashboard_shows_unavailable_when_superops_not_linked(): void
    {
        $client = Client::factory()->create(['superops_account_id' => null]);
        $admin = User::factory()->create([
            'client_id' => $client->id,
            'role' => UserRole::ClientAdmin,
        ]);

        config(['services.superops.api_token' => 'token', 'services.superops.subdomain' => 'onitltd']);

        $this->actingAs($admin)
            ->get(route('client-admin.dashboard'))
            ->assertOk()
            ->assertSee('SuperOps is not connected');
    }

    public function test_m365_refresh_dispatches_background_job(): void
    {
        Bus::fake();

        config([
            'services.entra_sync.client_id' => 'id',
            'services.entra_sync.client_secret' => 'secret',
        ]);

        $client = Client::factory()->create(['entra_tenant_id' => '11111111-1111-1111-1111-111111111111']);
        $admin = User::factory()->create([
            'client_id' => $client->id,
            'role' => UserRole::ClientAdmin,
        ]);

        Cache::put('m365_directory.client.'.$client->id, new M365DirectorySnapshot(collect(), collect(), now()), now()->addHour());

        $this->actingAs($admin)
            ->post(route('microsoft-365.directory.refresh'))
            ->assertRedirect(route('microsoft-365.directory'));

        Bus::assertDispatched(RefreshM365DirectoryJob::class);
    }
}
