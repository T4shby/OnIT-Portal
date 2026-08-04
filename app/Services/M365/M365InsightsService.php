<?php

namespace App\Services\M365;

use App\Jobs\RefreshM365InsightsJob;
use App\Models\Client;
use App\Services\EntraSync\MicrosoftGraphClient;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class M365InsightsService
{
    public function __construct(private MicrosoftGraphClient $graph) {}

    public function summaryForClient(Client $client, bool $manualRefresh = false): M365InsightsSummary
    {
        if (! filled($client->entra_tenant_id)) {
            return $this->unavailableSummary('Microsoft 365 is not connected for this organisation.');
        }

        if (! $this->graph->isConfigured()) {
            return $this->unavailableSummary('Microsoft Graph is not configured.');
        }

        $cached = Cache::get($this->cacheKey($client->id));

        if (is_array($cached)) {
            if ($manualRefresh) {
                $this->queueRefresh($client, respectCooldown: true);
            }

            // Serve cache on every visit; prewarm refreshes freshness.
            return $this->summaryFromCache($client->id, $cached);
        }

        $this->queueRefresh($client, respectCooldown: $manualRefresh);

        return new M365InsightsSummary(
            licensedUserCount: null,
            totalSeatsPurchased: null,
            totalSeatsAssigned: null,
            overallUtilizationPct: null,
            topSkus: [],
            lastRefreshedAt: null,
            isStale: true,
            refreshInProgress: true,
            unavailableReason: 'Data has not been synchronised yet.',
        );
    }

    public function queueRefresh(Client $client, bool $respectCooldown = false): bool
    {
        if (! filled($client->entra_tenant_id) || ! $this->graph->isConfigured()) {
            return false;
        }

        if ($respectCooldown) {
            $cooldownKey = 'm365_insights.refresh_cooldown.'.$client->id;
            if (Cache::has($cooldownKey)) {
                return false;
            }

            Cache::put($cooldownKey, true, now()->addSeconds(
                (int) config('services.m365_insights.insights_refresh_cooldown_seconds', 60),
            ));
        }

        if ($this->refreshInProgress($client->id)) {
            return false;
        }

        Cache::put('m365_insights.refresh_queued.'.$client->id, true, now()->addMinutes(5));
        RefreshM365InsightsJob::dispatch($client->id);

        return true;
    }

    public function refreshAndStore(Client $client): M365InsightsSummary
    {
        if (! filled($client->entra_tenant_id)) {
            throw new \RuntimeException('Microsoft 365 is not connected for this organisation.');
        }

        $lock = Cache::lock('m365_insights.refresh.'.$client->id, 300);

        if (! $lock->get()) {
            $cached = Cache::get($this->cacheKey($client->id));
            if (is_array($cached)) {
                return $this->summaryFromCache($client->id, $cached, refreshInProgress: true);
            }

            throw new \RuntimeException('Microsoft 365 insights refresh already in progress.');
        }

        try {
            $inventory = $this->graph->listSubscribedSkuInventory((string) $client->entra_tenant_id);
            $licensedUserCount = $this->licensedUserCount($client);
            $totalSeatsPurchased = array_sum(array_column($inventory, 'prepaidEnabled'));
            $totalSeatsAssigned = array_sum(array_column($inventory, 'consumedUnits'));

            $topSkus = array_map(
                static fn (array $sku): array => [
                    'skuPartNumber' => $sku['skuPartNumber'],
                    'purchased' => $sku['prepaidEnabled'],
                    'assigned' => $sku['consumedUnits'],
                    'utilizationPct' => $sku['utilizationPct'],
                ],
                $inventory,
            );

            usort($topSkus, static function (array $left, array $right): int {
                $byAssigned = $right['assigned'] <=> $left['assigned'];
                if ($byAssigned !== 0) {
                    return $byAssigned;
                }

                $byPurchased = $right['purchased'] <=> $left['purchased'];

                return $byPurchased !== 0
                    ? $byPurchased
                    : strcmp($left['skuPartNumber'], $right['skuPartNumber']);
            });

            $payload = [
                'licensed_user_count' => $licensedUserCount,
                'total_seats_purchased' => $totalSeatsPurchased,
                'total_seats_assigned' => $totalSeatsAssigned,
                'overall_utilization_pct' => $totalSeatsPurchased > 0
                    ? round(($totalSeatsAssigned / $totalSeatsPurchased) * 100, 1)
                    : 0.0,
                'top_skus' => array_slice($topSkus, 0, 5),
                'last_refreshed_at' => now()->toIso8601String(),
            ];

            Cache::put(
                $this->cacheKey($client->id),
                $payload,
                now()->addMinutes((int) config('services.m365_insights.insights_stale_minutes', 1440)),
            );

            return $this->summaryFromCache($client->id, $payload);
        } catch (Throwable $e) {
            Log::error('Microsoft 365 insights refresh failed', [
                'client_id' => $client->id,
                'error' => $e->getMessage(),
            ]);

            $cached = Cache::get($this->cacheKey($client->id));
            if (is_array($cached)) {
                return $this->summaryFromCache($client->id, $cached, isStale: true);
            }

            throw $e;
        } finally {
            Cache::forget('m365_insights.refresh_queued.'.$client->id);
            $lock->release();
        }
    }

    private function licensedUserCount(Client $client): int
    {
        $snapshot = Cache::get('m365_directory.client.'.$client->id);

        if ($snapshot instanceof M365DirectorySnapshot) {
            return $snapshot->peopleCounts()['users'];
        }

        return collect($this->graph->listTenantMemberUsers((string) $client->entra_tenant_id))
            ->filter(static fn (array $user): bool => ($user['assignedLicenseSkuIds'] ?? []) !== [])
            ->count();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function summaryFromCache(
        int $clientId,
        array $payload,
        bool $isStale = false,
        bool $refreshInProgress = false,
    ): M365InsightsSummary {
        $lastRefreshedAt = filled($payload['last_refreshed_at'] ?? null)
            ? Carbon::parse($payload['last_refreshed_at'])
            : null;

        if ($lastRefreshedAt && ! $isStale) {
            $isStale = $lastRefreshedAt->lte(now()->subMinutes(
                (int) config('services.m365_insights.insights_cache_minutes', 15),
            ));
        }

        return new M365InsightsSummary(
            licensedUserCount: isset($payload['licensed_user_count']) ? (int) $payload['licensed_user_count'] : null,
            totalSeatsPurchased: isset($payload['total_seats_purchased']) ? (int) $payload['total_seats_purchased'] : null,
            totalSeatsAssigned: isset($payload['total_seats_assigned']) ? (int) $payload['total_seats_assigned'] : null,
            overallUtilizationPct: isset($payload['overall_utilization_pct']) ? (float) $payload['overall_utilization_pct'] : null,
            topSkus: is_array($payload['top_skus'] ?? null) ? $payload['top_skus'] : [],
            lastRefreshedAt: $lastRefreshedAt,
            isStale: $isStale,
            refreshInProgress: $refreshInProgress || $this->refreshInProgress($clientId),
            unavailableReason: null,
        );
    }

    private function unavailableSummary(string $reason): M365InsightsSummary
    {
        return new M365InsightsSummary(
            licensedUserCount: null,
            totalSeatsPurchased: null,
            totalSeatsAssigned: null,
            overallUtilizationPct: null,
            topSkus: [],
            lastRefreshedAt: null,
            isStale: false,
            refreshInProgress: false,
            unavailableReason: $reason,
        );
    }

    private function refreshInProgress(int $clientId): bool
    {
        return Cache::has('m365_insights.refresh_queued.'.$clientId);
    }

    private function cacheKey(int $clientId): string
    {
        return "client:{$clientId}:m365-insights:v1";
    }
}
