<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Jobs\BootstrapClientEntraJob;
use App\Models\Client;
use App\Models\User;
use App\Services\EntraSync\CustomerEntraBootstrapService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class BootstrapEntraTest extends TestCase
{
    use RefreshDatabase;

    public function test_bootstrap_queues_background_job_immediately(): void
    {
        Queue::fake();

        $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
        $client = Client::factory()->create([
            'entra_tenant_id' => 'd6017e9f-4aba-43f1-94c1-56d3b9051f6f',
            'onboarding_checklist' => [],
        ]);

        $response = $this->actingAs($admin)->post(route('admin.clients.bootstrap-entra', $client));

        $response->assertRedirect(route('admin.clients.edit', $client));
        $response->assertSessionHas('success');
        $this->assertTrue(Cache::has(BootstrapClientEntraJob::IN_FLIGHT_KEY_PREFIX.$client->id));
        Queue::assertPushed(BootstrapClientEntraJob::class, fn (BootstrapClientEntraJob $job) => $job->clientId === $client->id);
    }

    public function test_bootstrap_without_tenant_id_is_not_queued(): void
    {
        Queue::fake();

        $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
        $client = Client::factory()->create([
            'entra_tenant_id' => null,
            'onboarding_checklist' => [],
        ]);

        $response = $this->actingAs($admin)->post(route('admin.clients.bootstrap-entra', $client));

        $response->assertRedirect(route('admin.clients.edit', $client));
        $response->assertSessionHas('error');
        Queue::assertNotPushed(BootstrapClientEntraJob::class);
    }

    public function test_job_records_result_and_logs_activity(): void
    {
        Queue::fake();

        $client = Client::factory()->create([
            'entra_tenant_id' => 'd6017e9f-4aba-43f1-94c1-56d3b9051f6f',
            'onboarding_checklist' => [],
        ]);

        $bootstrap = Mockery::mock(CustomerEntraBootstrapService::class);
        $bootstrap->shouldReceive('bootstrap')
            ->once()
            ->with(Mockery::type(Client::class), $client->entra_tenant_id)
            ->andReturn([
                'ok' => true,
                'summary' => 'Entra bootstrap complete.',
                'details' => ['Group created', 'SuperOps app created'],
                'warnings' => [],
            ]);
        $this->app->instance(CustomerEntraBootstrapService::class, $bootstrap);

        BootstrapClientEntraJob::markQueued($client->id);
        (new BootstrapClientEntraJob($client->id))->handle(
            app(CustomerEntraBootstrapService::class),
            app(\App\Services\ActivityLogService::class),
        );

        $this->assertFalse(Cache::has(BootstrapClientEntraJob::IN_FLIGHT_KEY_PREFIX.$client->id));
        $result = Cache::get(BootstrapClientEntraJob::LAST_RESULT_KEY_PREFIX.$client->id);
        $this->assertTrue($result['success'] ?? false);
        $this->assertStringContainsString('Entra bootstrap complete.', $result['message']);

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'client.entra_bootstrap',
            'client_id' => $client->id,
        ]);
    }
}
