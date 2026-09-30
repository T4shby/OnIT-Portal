<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Client;
use App\Models\User;
use App\Services\Portal\ClientActivityFeedService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class GlanceDashboardActivityTest extends TestCase
{
    use RefreshDatabase;

    public function test_malformed_activity_timestamp_does_not_500_the_home_page(): void
    {
        \Illuminate\Support\Facades\Http::fake();
        \Illuminate\Support\Facades\Bus::fake();

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
        $user = User::factory()->create(['client_id' => $client->id, 'role' => UserRole::ClientAdmin]);

        $feed = Mockery::mock(ClientActivityFeedService::class);
        $feed->shouldReceive('recentFor')->andReturn([
            ['at' => 'not-a-date-from-superops', 'source' => 'support', 'title' => 'Broken date ticket', 'detail' => 'x', 'href' => '/support/1', 'actions' => []],
            [
                'at' => now()->toIso8601String(),
                'source' => 'security',
                'title' => 'Good date ticket',
                'detail' => 'We are investigating a security case',
                'href' => null,
                'actions' => [
                    ['title' => 'We isolated a device', 'badge' => 'Completed'],
                ],
            ],
        ]);
        $this->app->instance(ClientActivityFeedService::class, $feed);

        $this->actingAs($user)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Broken date ticket')
            ->assertSee('Good date ticket')
            ->assertSee('Show detail')
            ->assertSee('We isolated a device')
            ->assertSee('Completed');
    }
}
