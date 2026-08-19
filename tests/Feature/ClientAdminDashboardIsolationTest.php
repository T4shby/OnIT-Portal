<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Client;
use App\Models\User;
use App\Services\M365\M365DirectorySnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ClientAdminDashboardIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_billing_admin_cannot_refresh_client_admin_dashboard(): void
    {
        $client = Client::factory()->create(['superops_account_id' => '111']);
        $user = User::factory()->create([
            'client_id' => $client->id,
            'role' => UserRole::ClientBillingAdmin,
        ]);

        $this->actingAs($user)
            ->post(route('client-admin.refresh'))
            ->assertForbidden();
    }

    public function test_m365_page_does_not_block_on_graph_when_cache_exists(): void
    {
        Bus::fake();

        config([
            'services.entra_sync.client_id' => 'id',
            'services.entra_sync.client_secret' => 'secret',
            'services.entra_sync.directory_cache_minutes' => 15,
        ]);

        $client = Client::factory()->create([
            'entra_tenant_id' => '11111111-1111-1111-1111-111111111111',
        ]);
        $admin = User::factory()->create([
            'client_id' => $client->id,
            'role' => UserRole::ClientAdmin,
        ]);

        $snapshot = new M365DirectorySnapshot(
            collect([[
                'displayName' => 'Cached Person',
                'email' => 'cached@acme.com',
                'type' => 'user',
                'typeLabel' => 'User Mailbox',
                'accountEnabled' => true,
                'licenses' => ['O365_BUSINESS_PREMIUM'],
                'portalLogin' => true,
            ]]),
            collect(),
            now()->subMinutes(5),
        );

        Cache::put('m365_directory.client.'.$client->id, $snapshot, now()->addDay());
        Cache::put('m365_directory.meta.'.$client->id, [
            'refreshed_at' => $snapshot->refreshedAt->toIso8601String(),
        ], now()->addDay());

        $this->actingAs($admin)
            ->get(route('microsoft-365.directory'))
            ->assertOk()
            ->assertSee('Cached Person');
    }

    public function test_client_admin_dashboard_shows_em_dash_not_zero_when_unavailable(): void
    {
        $client = Client::factory()->create([
            'superops_account_id' => null,
            'product_entitlements' => [
                'superops' => ['entitled' => true],
            ],
        ]);
        $admin = User::factory()->create([
            'client_id' => $client->id,
            'role' => UserRole::ClientAdmin,
        ]);

        config([
            'services.superops.api_token' => 'token',
            'services.superops.subdomain' => 'onitltd',
        ]);

        $this->actingAs($admin)
            ->get(route('client-admin.dashboard'))
            ->assertOk()
            ->assertSee('Please contact your account manager to get this sorted')
            ->assertDontSeeText('0 online');
    }
}
