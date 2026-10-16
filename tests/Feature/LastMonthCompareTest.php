<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Client;
use App\Models\User;
use App\Services\Portal\ClientMetricSnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LastMonthCompareTest extends TestCase
{
    use RefreshDatabase;

    public function test_last_month_stays_hidden_until_a_prior_month_exists(): void
    {
        [$user] = $this->clientAdmin();

        $this->actingAs($user)->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('Last month');
    }

    public function test_last_month_link_switches_the_home_page_to_stored_figures(): void
    {
        [$user, $client] = $this->clientAdmin();

        app(ClientMetricSnapshotService::class)->captureBundle($client, [
            'overall_band' => 'ok',
            'value' => ['threats' => '3', 'resolved' => '12', 'sla' => '98%'],
            'columns' => [],
        ], now()->subMonthNoOverflow()->endOfMonth());

        $this->actingAs($user)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Last month')
            ->assertSee('period=last', false)
            ->assertDontSee('Service figures on this page are from last month');

        $this->actingAs($user)->get(route('dashboard', ['period' => 'last']))
            ->assertOk()
            ->assertSee('Service figures on this page are from last month')
            ->assertSee('as of');

        $this->actingAs($user)->get(route('reports.index', ['period' => 'last']))
            ->assertOk()
            ->assertSee('Service figures on this page are from last month')
            ->assertSee('Last month');
    }

    /**
     * @return array{0: User, 1: Client}
     */
    private function clientAdmin(): array
    {
        Http::fake();
        Bus::fake();

        config([
            'services.entra_sync.client_id' => 'test-client',
            'services.entra_sync.client_secret' => 'test-secret',
            'services.superops.api_token' => 'token',
            'services.superops.subdomain' => 'onitltd',
        ]);

        $client = Client::factory()->create([
            'entra_tenant_id' => '11111111-1111-1111-1111-111111111111',
            'superops_account_id' => '123456789',
        ]);
        $user = User::factory()->create([
            'client_id' => $client->id,
            'role' => UserRole::ClientAdmin,
        ]);

        return [$user, $client];
    }
}
