<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Jobs\WarmClientOnboardingChecksJob;
use App\Models\Client;
use App\Models\User;
use App\Services\ClientOnboardingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The admin client index/edit pages must never make live Graph / SuperOps
 * calls (SCIM health, requester count) on a page load: per-row on the index
 * that was an N+1 of 30-60s-timeout external calls behind a 60s gateway.
 */
class AdminClientPagesNoLiveCallsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.entra_sync.client_id' => 'test-client',
            'services.entra_sync.client_secret' => 'test-secret',
            'services.superops.api_token' => 'token',
            'services.superops.subdomain' => 'onitltd',
        ]);
    }

    private function linkedClient(string $name): Client
    {
        return Client::factory()->create([
            'name' => $name,
            'superops_account_id' => '123'.random_int(1000, 9999),
            'entra_tenant_id' => '11111111-1111-1111-1111-111111111111',
            'entra_superops_app_id' => '22222222-2222-2222-2222-222222222222',
            'is_active' => true,
        ]);
    }

    public function test_index_makes_no_external_calls_and_queues_nothing(): void
    {
        Http::fake();
        Bus::fake();
        $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
        $this->linkedClient('Acme');
        $this->linkedClient('Globex');
        $this->linkedClient('Initech');

        $this->actingAs($admin)->get(route('admin.clients.index'))->assertOk();

        Http::assertNothingSent();
        Bus::assertNotDispatched(WarmClientOnboardingChecksJob::class);
        $this->assertFalse(Cache::has('superops.requester_count.'.Client::first()->id));
    }

    public function test_edit_reads_cache_only_and_queues_a_warm_job_when_cold(): void
    {
        Http::fake();
        Bus::fake();
        $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
        $client = $this->linkedClient('Acme');

        $this->actingAs($admin)->get(route('admin.clients.edit', $client))->assertOk();

        Http::assertNothingSent();
        Bus::assertDispatched(WarmClientOnboardingChecksJob::class, fn ($job) => $job->clientId === $client->id);
        $this->assertFalse(Cache::has(ClientOnboardingService::scimHealthCacheKey($client->id)));
    }

    public function test_edit_uses_warm_cache_and_does_not_requeue(): void
    {
        Http::fake();
        Bus::fake();
        $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
        $client = $this->linkedClient('Acme');

        Cache::put(ClientOnboardingService::scimHealthCacheKey($client->id), [
            'ok' => false,
            'needsApplyScim' => false,
            'error' => 'Quarantined: invalid credentials from warm cache',
        ], now()->addMinutes(5));
        Cache::put('superops.requester_count.'.$client->id, 3, now()->addMinutes(10));

        $this->actingAs($admin)->get(route('admin.clients.edit', $client))
            ->assertOk()
            ->assertSee('Quarantined: invalid credentials from warm cache');

        Http::assertNothingSent();
        Bus::assertNotDispatched(WarmClientOnboardingChecksJob::class);
    }

    public function test_warm_job_populates_both_caches(): void
    {
        $client = $this->linkedClient('Acme');

        $graph = \Mockery::mock(\App\Services\EntraSync\MicrosoftGraphClient::class);
        $graph->shouldReceive('getSuperOpsScimProvisioningHealth')->once()->andReturn(['ok' => true]);
        $this->app->instance(\App\Services\EntraSync\MicrosoftGraphClient::class, $graph);

        $sync = \Mockery::mock(\App\Services\SuperOps\SuperOpsUserSyncService::class);
        $sync->shouldReceive('countClientRequesters')->once()->andReturnUsing(function (Client $c) {
            Cache::put('superops.requester_count.'.$c->id, 42, now()->addMinutes(10));

            return 42;
        });

        (new WarmClientOnboardingChecksJob($client->id))->handle(app(ClientOnboardingService::class), $sync);

        $this->assertSame(['ok' => true], Cache::get(ClientOnboardingService::scimHealthCacheKey($client->id)));
        $this->assertSame(42, app(\App\Services\SuperOps\SuperOpsUserSyncService::class)->cachedClientRequesterCount($client));
    }
}
