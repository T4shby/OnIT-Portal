<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Client;
use App\Models\ClientMetricDailySnapshot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CaptureClientMetricSnapshotsTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_without_a_client_admin_gets_no_personal_scope_org_snapshot(): void
    {
        $client = Client::factory()->create(['is_active' => true]);
        User::factory()->create(['client_id' => $client->id, 'role' => UserRole::ClientRequester, 'is_active' => true]);
        User::factory()->create(['client_id' => $client->id, 'role' => UserRole::ClientBillingAdmin, 'is_active' => true]);

        $this->artisan('portal:capture-metric-snapshots', ['--client' => $client->id])
            ->expectsOutputToContain('no active client admin')
            ->assertSuccessful();

        $this->assertSame(0, ClientMetricDailySnapshot::query()->where('client_id', $client->id)->count());
    }

    public function test_client_with_a_client_admin_is_captured(): void
    {
        $client = Client::factory()->create(['is_active' => true]);
        User::factory()->create(['client_id' => $client->id, 'role' => UserRole::ClientRequester, 'is_active' => true]);
        User::factory()->create(['client_id' => $client->id, 'role' => UserRole::ClientAdmin, 'is_active' => true]);

        $this->artisan('portal:capture-metric-snapshots', ['--client' => $client->id])
            ->expectsOutputToContain('Captured snapshot for client #'.$client->id)
            ->assertSuccessful();

        $this->assertSame(1, ClientMetricDailySnapshot::query()->where('client_id', $client->id)->count());
    }
}
