<?php

namespace App\Console\Commands;

use App\Contracts\DashboardFeed;
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
 * Optional feeds when the jobs table is under capacity — **except cold sold feeds**,
 * which still queue so entitled products never stay “Never loaded” under backlog.
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
        $coldOptionalQueued = 0;

        Client::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->chunkById(100, function ($clients) use (
                $feeds,
                $queueDeep,
                &$clientsProcessed,
                &$criticalQueued,
                &$optionalQueued,
                &$coldOptionalQueued,
            ): void {
                foreach ($clients as $client) {
                    $clientsProcessed++;

                    foreach ($feeds->critical() as $feed) {
                        if ($feed->needsBackgroundRefresh($client) && $feed->queueRefresh($client)) {
                            $criticalQueued++;
                        }
                    }

                    foreach ($feeds->optional() as $feed) {
                        if (! $feed->needsBackgroundRefresh($client)) {
                            continue;
                        }

                        $cold = $this->isFeedCold($feed, $client);
                        // KPI: cold sold/optional feeds still prewarm under queue pressure.
                        if ($queueDeep && ! $cold) {
                            continue;
                        }

                        if ($feed->queueRefresh($client)) {
                            $optionalQueued++;
                            if ($cold) {
                                $coldOptionalQueued++;
                            }
                        }
                    }
                }
            });

        $this->info(
            "Dashboard prewarm: {$criticalQueued} critical, {$optionalQueued} optional"
            ." ({$coldOptionalQueued} cold),"
            ." across {$clientsProcessed} active client(s)"
            .($queueDeep ? ' (queue deep — warm optional skipped).' : '.')
        );

        if ($queueDeep) {
            $this->warn(
                "Warm optional skipped: {$pending} jobs already queued (max ".self::MAX_PENDING_BEFORE_OPTIONAL
                .'). Cold optional feeds still queue.'
            );
        }

        Cache::put(IntegrationHealthService::PREWARM_CACHE_KEY, [
            'at' => now()->toIso8601String(),
            'superops_queued' => $criticalQueued,
            'optional_queued' => $optionalQueued,
            'cold_optional_queued' => $coldOptionalQueued,
            'clients' => $clientsProcessed,
            'queue_deep' => $queueDeep,
            'pending_before' => $pending,
            'freshness' => app(\App\Services\Portal\PortalFreshnessService::class)->snapshot(),
        ], now()->addDay());

        return self::SUCCESS;
    }

    /**
     * True when this feed has no successful snapshot for the client (sold/mapped cold).
     */
    private function isFeedCold(DashboardFeed $feed, Client $client): bool
    {
        $key = match ($feed->key()) {
            'superops' => app(\App\Services\SuperOps\SuperOpsClientMetricsService::class)->cacheKey($client->id),
            'huntress' => app(\App\Services\Huntress\HuntressClientMetricsService::class)->cacheKey($client->id),
            'dropsuite' => app(\App\Services\Dropsuite\DropsuiteClientMetricsService::class)->cacheKey($client->id),
            'm365_insights' => app(\App\Services\M365\M365InsightsService::class)->cacheKey($client->id),
            'm365_directory' => 'm365_directory.meta.'.$client->id,
            default => null,
        };

        if ($key === null) {
            return false;
        }

        return Cache::get($key) === null;
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
