<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Services\Admin\IntegrationHealthService;
use App\Services\Portal\DashboardFeedRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Keep client dashboard caches filled so metrics exist before anyone opens the page.
 *
 * Critical feeds (SuperOps) always queue when due.
 * Optional feeds when the jobs table is under capacity.
 *
 * @see App\Contracts\DashboardFeed
 */
class PrewarmClientDashboardsCommand extends Command
{
    protected $signature = 'portal:prewarm-client-dashboards';

    protected $description = 'Ensure active clients have SuperOps (and other) dashboard caches ready';

    private const MAX_PENDING_BEFORE_OPTIONAL = 40;

    public function handle(DashboardFeedRegistry $feeds): int
    {
        $this->releaseStaleScheduleLocks();

        $pending = $this->pendingJobs();
        $queueDeep = $pending >= self::MAX_PENDING_BEFORE_OPTIONAL;

        $clientsProcessed = 0;
        $criticalQueued = 0;
        $optionalQueued = 0;

        Client::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->chunkById(100, function ($clients) use (
                $feeds,
                $queueDeep,
                &$clientsProcessed,
                &$criticalQueued,
                &$optionalQueued,
            ): void {
                foreach ($clients as $client) {
                    $clientsProcessed++;

                    foreach ($feeds->critical() as $feed) {
                        if ($feed->needsBackgroundRefresh($client) && $feed->queueRefresh($client)) {
                            $criticalQueued++;
                        }
                    }

                    if ($queueDeep) {
                        continue;
                    }

                    foreach ($feeds->optional() as $feed) {
                        if ($feed->needsBackgroundRefresh($client) && $feed->queueRefresh($client)) {
                            $optionalQueued++;
                        }
                    }
                }
            });

        $this->info(
            "Dashboard prewarm: {$criticalQueued} critical, {$optionalQueued} optional, "
            ."across {$clientsProcessed} active client(s)"
            .($queueDeep ? ' (queue deep — critical only).' : '.')
        );

        if ($queueDeep) {
            $this->warn("Optional prewarm skipped: {$pending} jobs already queued (max ".self::MAX_PENDING_BEFORE_OPTIONAL.').');
        }

        Cache::put(IntegrationHealthService::PREWARM_CACHE_KEY, [
            'at' => now()->toIso8601String(),
            'superops_queued' => $criticalQueued,
            'optional_queued' => $optionalQueued,
            'clients' => $clientsProcessed,
            'queue_deep' => $queueDeep,
            'pending_before' => $pending,
            'freshness' => app(\App\Services\Portal\PortalFreshnessService::class)->snapshot(),
        ], now()->addDay());

        return self::SUCCESS;
    }

    private function releaseStaleScheduleLocks(): void
    {
        if (! Schema::hasTable('cache_locks')) {
            return;
        }

        $now = time();
        DB::table('cache_locks')->where('expiration', '<', $now)->delete();

        DB::table('cache_locks')
            ->where('key', 'like', '%framework/schedule%')
            ->where('expiration', '>', $now + 1800)
            ->delete();
    }

    private function pendingJobs(): int
    {
        if (! Schema::hasTable('jobs')) {
            return 0;
        }

        return (int) DB::table('jobs')->count();
    }
}
