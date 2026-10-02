<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Jobs\RefreshDropsuiteBackupJob;
use App\Jobs\RefreshHuntressSecurityJob;
use App\Jobs\RefreshM365DirectoryJob;
use App\Jobs\RefreshM365InsightsJob;
use App\Jobs\RefreshSuperOpsDashboardJob;
use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class ClientFirstFeedPullTest extends TestCase
{
    use RefreshDatabase;

    public function test_saving_a_new_client_queues_the_first_pull_for_every_sold_feed(): void
    {
        Bus::fake();
        $this->configurePlatforms();

        $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
        $client = Client::factory()->create(['name' => 'Finishing Design']);

        $this->actingAs($admin)
            ->put(route('admin.clients.update', $client), $this->payload())
            ->assertRedirect(route('admin.clients.edit', $client));

        Bus::assertDispatched(RefreshSuperOpsDashboardJob::class, fn ($job) => $job->clientId === $client->id);
        Bus::assertDispatched(RefreshM365DirectoryJob::class, fn ($job) => $job->clientId === $client->id);
        Bus::assertDispatched(RefreshM365InsightsJob::class, fn ($job) => $job->clientId === $client->id);
        Bus::assertDispatched(RefreshHuntressSecurityJob::class, fn ($job) => $job->clientId === $client->id);
        Bus::assertDispatched(RefreshDropsuiteBackupJob::class, fn ($job) => $job->clientId === $client->id);
    }

    public function test_saving_again_does_not_requeue_feeds_that_already_have_a_snapshot(): void
    {
        Bus::fake();
        $this->configurePlatforms();

        $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
        $client = Client::factory()->create(['name' => 'Finishing Design']);
        $id = $client->id;

        cache()->put("client:{$id}:superops-dashboard:v5", ['last_refreshed_at' => now()->toIso8601String()], now()->addHour());
        cache()->put("client:{$id}:m365-insights:v4", ['last_refreshed_at' => now()->toIso8601String()], now()->addHour());
        cache()->put('m365_directory.meta.'.$id, ['refreshed_at' => now()->toIso8601String()], now()->addHour());
        cache()->put("client:{$id}:huntress-security:v1", ['last_refreshed_at' => now()->toIso8601String()], now()->addHour());
        cache()->put("client:{$id}:dropsuite-backup:v3", ['last_refreshed_at' => now()->toIso8601String()], now()->addHour());

        $this->actingAs($admin)
            ->put(route('admin.clients.update', $client), $this->payload())
            ->assertRedirect(route('admin.clients.edit', $client));

        Bus::assertNotDispatched(RefreshSuperOpsDashboardJob::class);
        Bus::assertNotDispatched(RefreshM365DirectoryJob::class);
        Bus::assertNotDispatched(RefreshM365InsightsJob::class);
        Bus::assertNotDispatched(RefreshHuntressSecurityJob::class);
        Bus::assertNotDispatched(RefreshDropsuiteBackupJob::class);
    }

    private function configurePlatforms(): void
    {
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
    }

    /**
     * @return array<string, string>
     */
    private function payload(): array
    {
        return [
            'name' => 'Finishing Design',
            'superops_account_id' => '3425667307281944576',
            'entra_tenant_id' => '11111111-1111-1111-1111-111111111111',
            'huntress_organization_id' => '123456',
            'dropsuite_organization_id' => 'org-10001',
            'entra_license_tier' => 'free',
            'is_active' => '1',
        ];
    }
}
