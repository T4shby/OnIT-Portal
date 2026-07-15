<?php

namespace Tests\Feature;

use App\Jobs\RefreshDropsuiteBackupJob;
use App\Jobs\RefreshHuntressSecurityJob;
use App\Jobs\RefreshM365DirectoryJob;
use App\Jobs\RefreshM365InsightsJob;
use App\Jobs\RefreshSuperOpsDashboardJob;
use App\Models\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class PrewarmClientDashboardsTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_queues_configured_dashboard_refreshes_for_active_clients(): void
    {
        Bus::fake();

        config([
            'services.superops.api_token' => 'token',
            'services.superops.subdomain' => 'onitltd',
            'services.entra_sync.client_id' => 'client-id',
            'services.entra_sync.client_secret' => 'client-secret',
            'services.huntress.enabled' => true,
            'services.huntress.api_key' => 'key',
            'services.huntress.api_secret' => 'secret',
            'services.dropsuite.enabled' => true,
            'services.dropsuite.api_url' => 'https://dropsuite.example/api',
            'services.dropsuite.reseller_token' => 'reseller',
            'services.dropsuite.auth_token' => 'auth',
        ]);

        $client = Client::factory()->create([
            'is_active' => true,
            'superops_account_id' => 'superops-client',
            'entra_tenant_id' => '11111111-1111-1111-1111-111111111111',
            'huntress_organization_id' => 'huntress-client',
            'dropsuite_organization_id' => 'dropsuite-client',
        ]);

        $this->artisan('portal:prewarm-client-dashboards')->assertSuccessful();

        Bus::assertDispatched(RefreshSuperOpsDashboardJob::class, fn ($job) => $job->clientId === $client->id);
        Bus::assertDispatched(RefreshM365DirectoryJob::class, fn ($job) => $job->clientId === $client->id);
        Bus::assertDispatched(RefreshM365InsightsJob::class, fn ($job) => $job->clientId === $client->id);
        Bus::assertDispatched(RefreshHuntressSecurityJob::class, fn ($job) => $job->clientId === $client->id);
        Bus::assertDispatched(RefreshDropsuiteBackupJob::class, fn ($job) => $job->clientId === $client->id);
    }
}
