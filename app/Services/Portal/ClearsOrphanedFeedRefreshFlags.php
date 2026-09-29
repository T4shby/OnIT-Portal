<?php

namespace App\Services\Portal;

use App\Support\QueuedJobPayload;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Clear stuck refresh_queued / refresh_started when the jobs table has no matching job.
 *
 * Usage: $this->clearOrphanedFeedFlags($clientId, 'huntress_security', 'RefreshHuntressSecurityJob');
 * Queued key = {prefix}.refresh_queued.{id}
 * Started key = {prefix}.refresh_started.{id}
 */
trait ClearsOrphanedFeedRefreshFlags
{
    protected function clearOrphanedFeedFlags(int $clientId, string $cachePrefix, string $jobClassHint): void
    {
        $queuedKey = $cachePrefix.'.refresh_queued.'.$clientId;
        $startedKey = $cachePrefix.'.refresh_started.'.$clientId;

        if (! Cache::has($queuedKey)) {
            return;
        }

        if ($this->feedJobPendingInDatabase($clientId, $jobClassHint)) {
            return;
        }

        Cache::forget($queuedKey);
        Cache::forget($startedKey);
    }

    protected function feedJobPendingInDatabase(int $clientId, string $jobClassHint): bool
    {
        if (! Schema::hasTable('jobs')) {
            return false;
        }

        return QueuedJobPayload::whereClientId(
            DB::table('jobs')->where('payload', 'like', '%'.$jobClassHint.'%'),
            $clientId,
        )->exists();
    }
}
