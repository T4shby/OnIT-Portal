<?php

namespace App\Services\Huntress;

use App\Jobs\RefreshHuntressSecurityJob;
use App\Models\Client;
use App\Services\Portal\ClearsOrphanedFeedRefreshFlags;
use App\Services\Portal\PortalFreshnessService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Client Admin Huntress security metrics.
 *
 * Cache key: client:{id}:huntress-security:v1
 * Source: GET /v1/organizations/{huntress_organization_id}
 *
 * @see https://api.huntress.io/docs
 * @see App\Contracts\DashboardFeed (HuntressDashboardFeed adapter)
 */
class HuntressClientMetricsService
{
    use ClearsOrphanedFeedRefreshFlags;

    /** Cache retention (payload kept this long even if stale for UI). */
    private const STALE_RETENTION_MINUTES = 1440;

    private const REFRESH_COOLDOWN_SECONDS = 60;

    public function __construct(private HuntressApiClient $api) {}

    public function isAvailable(): bool
    {
        return $this->api->isConfigured();
    }

    public function summaryForClient(Client $client, bool $manualRefresh = false): HuntressClientSecuritySummary
    {
        if (! $this->isAvailable()) {
            return $this->unavailableSummary('Huntress API is not configured.');
        }

        if (empty($client->huntress_organization_id)) {
            return $this->unavailableSummary('Huntress is not connected for this organisation.');
        }

        $cacheKey = $this->cacheKey($client->id);
        $cached = Cache::get($cacheKey);

        if (is_array($cached) && ! $manualRefresh) {
            return $this->summaryFromCache($client->id, $cached);
        }

        if ($manualRefresh) {
            $this->queueRefresh($client);
            $cached = Cache::get($cacheKey);
            if (is_array($cached)) {
                return $this->summaryFromCache($client->id, $cached, refreshInProgress: true);
            }
        } else {
            $this->queueRefresh($client);
        }

        if (is_array($cached)) {
            return $this->summaryFromCache($client->id, $cached, refreshInProgress: true);
        }

        return new HuntressClientSecuritySummary(
            agentsTotal: null,
            agentsUnresponsive: null,
            openIncidents: null,
            edrIsolatedAgents: null,
            available: false,
            unavailableReason: 'Data has not been synchronised yet.',
            lastRefreshedAt: null,
            isStale: true,
            refreshInProgress: true,
        );
    }

    public function queueRefresh(Client $client, bool $respectCooldown = false): bool
    {
        if (empty($client->huntress_organization_id) || ! $this->isAvailable()) {
            return false;
        }

        $this->clearOrphanedFeedFlags($client->id, 'huntress_security', 'RefreshHuntressSecurityJob');

        if ($respectCooldown) {
            $cooldownKey = 'huntress_security.refresh_cooldown.'.$client->id;
            if (Cache::has($cooldownKey)) {
                return false;
            }
            Cache::put($cooldownKey, true, now()->addSeconds(self::REFRESH_COOLDOWN_SECONDS));
        }

        if (Cache::has('huntress_security.refresh_queued.'.$client->id)) {
            return false;
        }

        Cache::put('huntress_security.refresh_queued.'.$client->id, true, now()->addMinutes(5));
        RefreshHuntressSecurityJob::dispatch($client->id);

        return true;
    }

    public function needsBackgroundRefresh(Client $client): bool
    {
        if (empty($client->huntress_organization_id) || ! $this->isAvailable()) {
            return false;
        }

        $this->clearOrphanedFeedFlags($client->id, 'huntress_security', 'RefreshHuntressSecurityJob');

        if (Cache::has('huntress_security.refresh_queued.'.$client->id)) {
            return false;
        }

        $cached = Cache::get($this->cacheKey($client->id));
        if (! is_array($cached) || ! filled($cached['last_refreshed_at'] ?? null)) {
            return true;
        }

        $after = max(0.5, app(PortalFreshnessService::class)->effectiveRequeueMinutes());
        $seconds = max(30, (int) round($after * 60) - 20);

        return Carbon::parse($cached['last_refreshed_at'])->lte(now()->subSeconds($seconds));
    }

    public function refreshAndStore(Client $client): HuntressClientSecuritySummary
    {
        $organizationId = (string) $client->huntress_organization_id;
        $lock = Cache::lock('huntress_security.refresh.'.$client->id, 300);

        if (! $lock->get()) {
            $cached = Cache::get($this->cacheKey($client->id));
            if (is_array($cached)) {
                return $this->summaryFromCache($client->id, $cached, refreshInProgress: true);
            }

            throw new \RuntimeException('Huntress security refresh already in progress.');
        }

        try {
            $payload = $this->api->get('organizations/'.$organizationId);
            $mapped = $this->mapOrganizationPayload($payload);
            $mapped['last_refreshed_at'] = now()->toIso8601String();

            Cache::put(
                $this->cacheKey($client->id),
                $mapped,
                now()->addMinutes(self::STALE_RETENTION_MINUTES),
            );

            return $this->summaryFromCache($client->id, $mapped);
        } catch (Throwable $e) {
            Log::error('Huntress security refresh failed', [
                'client_id' => $client->id,
                'organization_id' => $organizationId,
                'error' => $e->getMessage(),
            ]);

            $cached = Cache::get($this->cacheKey($client->id));
            if (is_array($cached)) {
                return $this->summaryFromCache($client->id, $cached, isStale: true);
            }

            throw $e;
        } finally {
            Cache::forget('huntress_security.refresh_queued.'.$client->id);
            $lock->release();
        }
    }

    /**
     * Map GET /v1/organizations/{id} JSON into a cache payload.
     *
     * Official org responses nest EDR under `edr`; flat keys are accepted as fallbacks.
     * Field names were verified against Huntress public API shape (org open incidents + edr counts).
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function mapOrganizationPayload(array $payload): array
    {
        $org = is_array($payload['organization'] ?? null)
            ? $payload['organization']
            : $payload;

        $edr = is_array($org['edr'] ?? null) ? $org['edr'] : [];

        return [
            'agents_total' => $this->nullableInt(
                $edr['agents_count']
                    ?? $org['agents_count']
                    ?? $org['agent_count']
                    ?? null
            ),
            'agents_unresponsive' => $this->nullableInt(
                $edr['unresponsive_agents_count']
                    ?? $org['unresponsive_agents_count']
                    ?? null
            ),
            'open_incidents' => $this->nullableInt(
                $org['open_incident_reports_count']
                    ?? $org['open_incidents_count']
                    ?? $org['incident_reports_count']
                    ?? null
            ),
            'edr_isolated_agents' => $this->nullableInt(
                $edr['isolated_agents_count']
                    ?? $org['isolated_agents_count']
                    ?? null
            ),
        ];
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function summaryFromCache(
        int $clientId,
        array $payload,
        bool $isStale = false,
        bool $refreshInProgress = false,
    ): HuntressClientSecuritySummary {
        $lastRefreshedAt = filled($payload['last_refreshed_at'] ?? null)
            ? Carbon::parse($payload['last_refreshed_at'])
            : null;

        if ($lastRefreshedAt && ! $isStale) {
            $soft = max(1, app(PortalFreshnessService::class)->effectiveSoftWindowMinutes());
            $isStale = $lastRefreshedAt->lte(now()->subMinutes($soft));
        }

        return new HuntressClientSecuritySummary(
            agentsTotal: $payload['agents_total'] ?? null,
            agentsUnresponsive: $payload['agents_unresponsive'] ?? null,
            openIncidents: $payload['open_incidents'] ?? null,
            edrIsolatedAgents: $payload['edr_isolated_agents'] ?? null,
            available: true,
            unavailableReason: null,
            lastRefreshedAt: $lastRefreshedAt,
            isStale: $isStale,
            refreshInProgress: $refreshInProgress || Cache::has('huntress_security.refresh_queued.'.$clientId),
        );
    }

    private function unavailableSummary(string $reason): HuntressClientSecuritySummary
    {
        return new HuntressClientSecuritySummary(
            agentsTotal: null,
            agentsUnresponsive: null,
            openIncidents: null,
            edrIsolatedAgents: null,
            available: false,
            unavailableReason: $reason,
            lastRefreshedAt: null,
            isStale: false,
            refreshInProgress: false,
        );
    }

    public function cacheKey(int $clientId): string
    {
        return "client:{$clientId}:huntress-security:v1";
    }
}
