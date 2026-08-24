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
        if (! app(\App\Services\Portal\ClientProductService::class)->isEntitled($client, 'm365')) {
            return $this->unavailableSummary('Microsoft 365 is not sold for this organisation.');
        }

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
            secureScorePct: null,
            mfaRegisteredPct: null,
            mfaUserSample: null,
            securityMetricsNote: null,
        );
    }

    public function queueRefresh(Client $client, bool $respectCooldown = false): bool
    {
        if (! app(\App\Services\Portal\ClientProductService::class)->shouldRefresh($client, 'm365')) {
            return false;
        }

        $this->clearOrphanedRefreshFlags($client->id);

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

        Cache::put('m365_insights.refresh_queued.'.$client->id, true, now()->addMinutes(15));
        RefreshM365InsightsJob::dispatch($client->id);

        return true;
    }

    /**
     * Cold or past insights requeue threshold - used by prewarm.
     * Requeues at insights_refresh_after_minutes (default 2.5) with the shared prewarm cadence.
     */
    public function needsBackgroundRefresh(Client $client): bool
    {
        if (! app(\App\Services\Portal\ClientProductService::class)->shouldRefresh($client, 'm365')) {
            return false;
        }

        $this->clearOrphanedRefreshFlags($client->id);

        if ($this->refreshInProgress($client->id)) {
            return false;
        }

        $cached = Cache::get($this->cacheKey($client->id));
        if (! is_array($cached)) {
            return true;
        }

        if (! filled($cached['last_refreshed_at'] ?? null)) {
            return true;
        }

        $after = max(0.5, app(\App\Services\Portal\PortalFreshnessService::class)->effectiveRequeueMinutes());
        $seconds = max(30, (int) round($after * 60) - 20);

        return Carbon::parse($cached['last_refreshed_at'])->lte(now()->subSeconds($seconds));
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

            $billable = array_values(array_filter(
                $inventory,
                static fn (array $sku): bool => MicrosoftLicenseSkuNames::countsTowardOverallUtilisation(
                    $sku['skuPartNumber'],
                    (int) $sku['prepaidEnabled'],
                    (int) $sku['consumedUnits'],
                ),
            ));

            $totalSeatsPurchased = (int) array_sum(array_column($billable, 'prepaidEnabled'));
            $totalSeatsAssigned = (int) array_sum(array_column($billable, 'consumedUnits'));

            $topSkus = array_map(
                static fn (array $sku): array => [
                    'skuPartNumber' => $sku['skuPartNumber'],
                    'displayName' => MicrosoftLicenseSkuNames::displayName($sku['skuPartNumber']),
                    'purchased' => $sku['prepaidEnabled'],
                    'assigned' => $sku['consumedUnits'],
                    'utilizationPct' => $sku['utilizationPct'],
                    'countsTowardUtilisation' => MicrosoftLicenseSkuNames::countsTowardOverallUtilisation(
                        $sku['skuPartNumber'],
                        (int) $sku['prepaidEnabled'],
                        (int) $sku['consumedUnits'],
                    ),
                ],
                $inventory,
            );

            // Prefer paid/commercial licences in the top list; free bulk SKUs last.
            usort($topSkus, static function (array $left, array $right): int {
                $leftBillable = $left['countsTowardUtilisation'] ? 0 : 1;
                $rightBillable = $right['countsTowardUtilisation'] ? 0 : 1;
                if ($leftBillable !== $rightBillable) {
                    return $leftBillable <=> $rightBillable;
                }

                $byAssigned = $right['assigned'] <=> $left['assigned'];
                if ($byAssigned !== 0) {
                    return $byAssigned;
                }

                $byPurchased = $right['purchased'] <=> $left['purchased'];

                return $byPurchased !== 0
                    ? $byPurchased
                    : strcmp($left['displayName'], $right['displayName']);
            });

            // Same marketing name from different Graph part numbers (e.g. SPB + O365_BUSINESS_PREMIUM).
            $nameCounts = array_count_values(array_column($topSkus, 'displayName'));
            $topSkus = array_map(static function (array $sku) use ($nameCounts): array {
                if (($nameCounts[$sku['displayName']] ?? 0) > 1) {
                    $sku['displayName'] = $sku['displayName'].' · '.$sku['skuPartNumber'];
                }

                return $sku;
            }, $topSkus);

            $secure = $this->graph->getSecureScoreSummary((string) $client->entra_tenant_id);
            $mfa = $this->graph->getMfaRegistrationSummary((string) $client->entra_tenant_id);

            $securityNotes = array_values(array_filter([
                ! ($secure['available'] ?? false) ? ($secure['reason'] ?? null) : null,
                ! ($mfa['available'] ?? false) ? ($mfa['reason'] ?? null) : null,
            ]));

            $payload = [
                'licensed_user_count' => $licensedUserCount,
                'total_seats_purchased' => $totalSeatsPurchased,
                'total_seats_assigned' => $totalSeatsAssigned,
                'overall_utilization_pct' => $totalSeatsPurchased > 0
                    ? round(($totalSeatsAssigned / $totalSeatsPurchased) * 100, 1)
                    : 0.0,
                'top_skus' => array_slice($topSkus, 0, 8),
                'all_skus' => $topSkus,
                'secure_score_pct' => ($secure['available'] ?? false) ? $secure['percentage'] : null,
                'mfa_registered_pct' => ($mfa['available'] ?? false) ? $mfa['registered_pct'] : null,
                'mfa_user_sample' => ($mfa['available'] ?? false) ? $mfa['total_users'] : null,
                'security_metrics_note' => $securityNotes !== [] ? implode(',', $securityNotes) : null,
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
                (float) config('services.m365_insights.insights_cache_minutes', 5),
            ));
        }

        return new M365InsightsSummary(
            licensedUserCount: isset($payload['licensed_user_count']) ? (int) $payload['licensed_user_count'] : null,
            totalSeatsPurchased: isset($payload['total_seats_purchased']) ? (int) $payload['total_seats_purchased'] : null,
            totalSeatsAssigned: isset($payload['total_seats_assigned']) ? (int) $payload['total_seats_assigned'] : null,
            overallUtilizationPct: isset($payload['overall_utilization_pct']) ? (float) $payload['overall_utilization_pct'] : null,
            topSkus: is_array($payload['top_skus'] ?? null)
                ? array_map(static function (array $sku): array {
                    $part = (string) ($sku['skuPartNumber'] ?? '');

                    return [
                        'skuPartNumber' => $part,
                        'displayName' => (string) ($sku['displayName'] ?? MicrosoftLicenseSkuNames::displayName($part)),
                        'purchased' => (int) ($sku['purchased'] ?? 0),
                        'assigned' => (int) ($sku['assigned'] ?? 0),
                        'utilizationPct' => (float) ($sku['utilizationPct'] ?? 0),
                        'countsTowardUtilisation' => (bool) ($sku['countsTowardUtilisation']
                            ?? MicrosoftLicenseSkuNames::countsTowardOverallUtilisation(
                                $part,
                                (int) ($sku['purchased'] ?? 0),
                                (int) ($sku['assigned'] ?? 0),
                            )),
                    ];
                }, $payload['top_skus'])
                : [],
            lastRefreshedAt: $lastRefreshedAt,
            isStale: $isStale,
            refreshInProgress: $refreshInProgress || $this->refreshInProgress($clientId),
            unavailableReason: null,
            secureScorePct: isset($payload['secure_score_pct']) && is_numeric($payload['secure_score_pct'])
                ? (float) $payload['secure_score_pct']
                : null,
            mfaRegisteredPct: isset($payload['mfa_registered_pct']) && is_numeric($payload['mfa_registered_pct'])
                ? (float) $payload['mfa_registered_pct']
                : null,
            mfaUserSample: isset($payload['mfa_user_sample']) && is_numeric($payload['mfa_user_sample'])
                ? (int) $payload['mfa_user_sample']
                : null,
            securityMetricsNote: isset($payload['security_metrics_note'])
                ? (string) $payload['security_metrics_note']
                : null,
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
            secureScorePct: null,
            mfaRegisteredPct: null,
            mfaUserSample: null,
            securityMetricsNote: null,
        );
    }

    private function refreshInProgress(int $clientId): bool
    {
        return Cache::has('m365_insights.refresh_queued.'.$clientId);
    }

    private function clearOrphanedRefreshFlags(int $clientId): void
    {
        if (! Cache::has('m365_insights.refresh_queued.'.$clientId)) {
            return;
        }

        if (! \Illuminate\Support\Facades\Schema::hasTable('jobs')) {
            return;
        }

        $pending = \Illuminate\Support\Facades\DB::table('jobs')
            ->where(function ($q) use ($clientId): void {
                $q->where('payload', 'like', '%RefreshM365InsightsJob%')
                    ->orWhere('payload', 'like', '%M365Insights%');
            })
            ->where(function ($q) use ($clientId): void {
                $id = (int) $clientId;
                $q->where('payload', 'like', '%clientId";i:'.$id.';%')
                    ->orWhere('payload', 'like', '%"clientId":'.$id.'%')
                    ->orWhere('payload', 'like', '%i:'.$id.';%');
            })
            ->exists();

        if (! $pending) {
            Cache::forget('m365_insights.refresh_queued.'.$clientId);
            Cache::forget('m365_insights.refresh_started.'.$clientId);
        }
    }

    public function cacheKey(int $clientId): string
    {
        return "client:{$clientId}:m365-insights:v4";
    }
}
