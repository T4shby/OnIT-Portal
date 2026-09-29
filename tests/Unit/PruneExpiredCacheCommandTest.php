<?php

namespace Tests\Unit;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PruneExpiredCacheCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_deletes_only_expired_rows_from_the_database_cache(): void
    {
        config(['cache.default' => 'database']);

        Cache::store('database')->put('superops-ticket-account:old', 'acc-1', 60);
        Cache::store('database')->put('still-fresh', 'value', 3600);
        Cache::store('database')->forever('forever-key', 'value');

        // Age the first entry past its expiry without reading it again.
        DB::table('cache')->where('key', 'like', '%superops-ticket-account:old')->update(['expiration' => time() - 10]);

        $this->artisan('portal:prune-expired-cache')->assertSuccessful();

        $keys = DB::table('cache')->pluck('key')->all();
        $this->assertCount(2, $keys);
        $this->assertTrue(Cache::store('database')->has('still-fresh'));
        $this->assertTrue(Cache::store('database')->has('forever-key'));
    }

    public function test_growth_pruning_is_scheduled(): void
    {
        $commands = collect(app(Schedule::class)->events())->pluck('command')->implode("\n");

        $this->assertStringContainsString('portal:prune-expired-cache', $commands);
        $this->assertStringContainsString('queue:prune-failed', $commands);
    }
}
