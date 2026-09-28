<?php

namespace Tests\Unit\Services\Portal;

use App\Enums\UserRole;
use App\Models\Client;
use App\Models\User;
use App\Services\Portal\PortalFreshnessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PortalFreshnessSessionCountCacheTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Production uses CACHE_STORE=database - that is where the orphaned rows piled up.
        config(['cache.default' => 'database']);
        Cache::forgetDriver('database');
    }

    private function customerSession(Client $client): void
    {
        $user = User::factory()->create(['role' => UserRole::ClientRequester, 'client_id' => $client->id]);

        DB::table('sessions')->insert([
            'id' => Str::random(40),
            'user_id' => $user->id,
            'payload' => '',
            'last_activity' => now()->getTimestamp(),
        ]);
    }

    public function test_count_is_cached_across_seconds_and_does_not_grow_the_cache_table(): void
    {
        $this->freezeTime();
        $client = Client::factory()->create();
        $this->customerSession($client);
        $freshness = app(PortalFreshnessService::class);

        $this->assertSame(1, $freshness->activeCustomerSessionCount());
        $rowsAfterFirst = DB::table('cache')->count();

        $this->customerSession($client);

        foreach (range(1, 5) as $second) {
            $this->travel(1)->seconds();
            // Still inside the 20s window: served from cache, no new cache rows.
            $this->assertSame(1, $freshness->activeCustomerSessionCount());
        }

        $this->assertSame($rowsAfterFirst, DB::table('cache')->count());

        $this->travel(20)->seconds();
        $this->assertSame(2, $freshness->activeCustomerSessionCount());
        $this->assertSame($rowsAfterFirst, DB::table('cache')->count());
    }
}
