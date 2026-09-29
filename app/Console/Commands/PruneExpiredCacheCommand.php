<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * With CACHE_STORE=database, Laravel deletes an expired `cache` row only when that
 * exact key is read again, and Brain/Deployment.md says not to cache:clear on
 * deploys. Keys that are written once and never read again after they expire
 * (e.g. the per-ticket `superops-ticket-account:{id}` entries, results for deleted
 * clients) therefore stay in the table forever. This deletes only rows that are
 * already expired, which is exactly what the store itself does on read.
 */
class PruneExpiredCacheCommand extends Command
{
    protected $signature = 'portal:prune-expired-cache';

    protected $description = 'Delete expired rows from the database cache table';

    public function handle(): int
    {
        if (config('cache.default') !== 'database') {
            $this->info('Cache store is not database - nothing to prune.');

            return self::SUCCESS;
        }

        $table = (string) config('cache.stores.database.table', 'cache');
        if (! Schema::hasTable($table)) {
            return self::SUCCESS;
        }

        $deleted = DB::table($table)->where('expiration', '<=', time())->delete();

        $this->info("Deleted {$deleted} expired cache row(s).");

        return self::SUCCESS;
    }
}
