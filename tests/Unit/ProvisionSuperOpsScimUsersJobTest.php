<?php

namespace Tests\Unit;

use App\Jobs\ProvisionSuperOpsScimUsersJob;
use App\Models\Client;
use App\Services\EntraSync\EntraGroupSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

class ProvisionSuperOpsScimUsersJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_pending_ids_are_restored_when_provisioning_throws_so_a_retry_keeps_them(): void
    {
        $client = Client::factory()->create();
        $key = 'entra_scim_provision_pending.'.$client->id;
        Cache::put($key, ['u1', 'u2'], now()->addMinutes(30));

        $sync = Mockery::mock(EntraGroupSyncService::class);
        $sync->shouldReceive('provisionSuperOpsScimUsers')->once()->andThrow(new \RuntimeException('Graph 503'));

        try {
            (new ProvisionSuperOpsScimUsersJob($client->id))->handle($sync);
            $this->fail('Expected the provisioning failure to propagate for a queue retry.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Graph 503', $e->getMessage());
        }

        $this->assertEqualsCanonicalizing(['u1', 'u2'], Cache::get($key));

        $retry = Mockery::mock(EntraGroupSyncService::class);
        $retry->shouldReceive('provisionSuperOpsScimUsers')
            ->once()
            ->withArgs(fn ($c, array $ids) => $c->is($client) && count($ids) === 2)
            ->andReturn([2, []]);

        (new ProvisionSuperOpsScimUsersJob($client->id))->handle($retry);
        $this->assertNull(Cache::get($key));
    }
}
