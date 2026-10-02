<?php

namespace App\Services\Portal;

use App\Contracts\DashboardFeed;
use App\Models\Client;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Ordered list of Client Admin / prewarm feeds (modular dashboard integrations).
 */
class DashboardFeedRegistry
{
    /**
     * @param  list<DashboardFeed>  $feeds
     */
    public function __construct(
        private array $feeds,
    ) {}

    /**
     * @return list<DashboardFeed>
     */
    public function all(): array
    {
        return $this->feeds;
    }

    /**
     * @return list<DashboardFeed>
     */
    public function critical(): array
    {
        return array_values(array_filter(
            $this->feeds,
            fn (DashboardFeed $f): bool => $f->prewarmPriority() === 'critical',
        ));
    }

    /**
     * @return list<DashboardFeed>
     */
    public function optional(): array
    {
        return array_values(array_filter(
            $this->feeds,
            fn (DashboardFeed $f): bool => $f->prewarmPriority() === 'optional',
        ));
    }

    /**
     * Feeds that render a card in the System health grid.
     *
     * @return list<DashboardFeed>
     */
    public function overviewTiles(): array
    {
        return array_values(array_filter(
            $this->feeds,
            fn (DashboardFeed $f): bool => filled($f->overviewPartial()),
        ));
    }

    public function get(string $key): ?DashboardFeed
    {
        foreach ($this->feeds as $feed) {
            if ($feed->key() === $key) {
                return $feed;
            }
        }

        return null;
    }

    /**
     * Summaries keyed by feed key + BC view variable names.
     *
     * @return array{by_key: array<string, object>, view: array<string, object>}
     */
    public function summariesForClient(Client $client, bool $manualRefresh = false, ?\App\Models\User $viewer = null): array
    {
        $byKey = [];
        $view = [];
        $visibility = app(ClientVisibilityService::class);
        $orgWide = $viewer === null || $visibility->canViewOrganisationWide($viewer, $client);

        foreach ($this->feeds as $feed) {
            if ($feed->key() === 'm365_directory') {
                continue;
            }

            // Licence fleet stays Client Admin / staff only. Backups stay visible for
            // requesters as a personal "last backed up" card (org-wide for admins).
            if (! $orgWide && $feed->key() === 'm365_insights') {
                continue;
            }

            $summary = match ($feed->key()) {
                'superops' => app(\App\Services\SuperOps\SuperOpsClientMetricsService::class)
                    ->summaryForClient($client, $manualRefresh, $viewer),
                'huntress' => $this->huntressSummaryForViewer($client, $manualRefresh, $viewer, $orgWide),
                'dropsuite' => app(\App\Services\Dropsuite\DropsuiteClientMetricsService::class)
                    ->summaryForClient($client, $manualRefresh, $viewer),
                default => $feed->summaryForClient($client, $manualRefresh),
            };

            $byKey[$feed->key()] = $summary;
            $view[$feed->summaryViewVar()] = $summary;
        }

        // Ensure BC vars exist when feeds skipped for personal scope.
        if (! isset($view['m365Insights'])) {
            $view['m365Insights'] = new \App\Services\M365\M365InsightsSummary(
                licensedUserCount: null,
                totalSeatsPurchased: null,
                totalSeatsAssigned: null,
                overallUtilizationPct: null,
                topSkus: [],
                lastRefreshedAt: null,
                isStale: false,
                refreshInProgress: false,
                unavailableReason: 'Organisation licence insight is available to Client Admins.',
            );
        }

        return [
            'by_key' => $byKey,
            'view' => $view,
        ];
    }

    private function huntressSummaryForViewer(
        Client $client,
        bool $manualRefresh,
        ?\App\Models\User $viewer,
        bool $orgWide,
    ): object {
        $metrics = app(\App\Services\Huntress\HuntressClientMetricsService::class);
        $summary = $metrics->summaryForClient($client, $manualRefresh);
        if ($orgWide || $viewer === null || ! $summary->hasData()) {
            return $summary;
        }

        $list = app(\App\Services\Huntress\HuntressIncidentService::class)
            ->listForClient($client, null, $viewer);

        return new \App\Services\Huntress\HuntressClientSecuritySummary(
            agentsTotal: null,
            agentsUnresponsive: null,
            openIncidents: $list->activeCount,
            resolvedIncidents: $list->resolvedCount,
            edrIsolatedAgents: null,
            available: $list->available || $summary->available,
            unavailableReason: $list->available ? null : $summary->unavailableReason,
            lastRefreshedAt: $list->lastRefreshedAt ?? $summary->lastRefreshedAt,
            isStale: $list->isStale || $summary->isStale,
            refreshInProgress: $list->refreshInProgress || $summary->refreshInProgress,
        );
    }

    /**
     * First snapshot for each sold, mapped feed. Later freshness stays on adaptive prewarm.
     */
    public function queueMissingSnapshots(Client $client): int
    {
        $queued = 0;

        foreach ($this->feeds as $feed) {
            if ($this->hasSnapshot($feed, $client)) {
                continue;
            }

            if ($feed->queueRefresh($client)) {
                $queued++;
            }
        }

        return $queued;
    }

    private function hasSnapshot(DashboardFeed $feed, Client $client): bool
    {
        $key = match ($feed->key()) {
            'superops' => app(\App\Services\SuperOps\SuperOpsClientMetricsService::class)->cacheKey($client->id),
            'huntress' => app(\App\Services\Huntress\HuntressClientMetricsService::class)->cacheKey($client->id),
            'dropsuite' => app(\App\Services\Dropsuite\DropsuiteClientMetricsService::class)->cacheKey($client->id),
            'm365_insights' => app(\App\Services\M365\M365InsightsService::class)->cacheKey($client->id),
            'm365_directory' => 'm365_directory.meta.'.$client->id,
            'usecure' => class_exists(\App\Services\Usecure\UsecureClientMetricsService::class)
                ? app(\App\Services\Usecure\UsecureClientMetricsService::class)->cacheKey($client->id)
                : null,
            default => null,
        };

        if ($key === null) {
            return true;
        }

        return Cache::get($key) !== null;
    }

    /**
     * Queue refresh for all feeds that support overview refresh (not directory).
     */
    public function queueOverviewRefresh(Client $client, bool $respectCooldown = true): bool
    {
        $queued = false;

        foreach ($this->feeds as $feed) {
            if ($feed->key() === 'm365_directory') {
                continue;
            }
            if ($feed->queueRefresh($client, $respectCooldown)) {
                $queued = true;
            }
        }

        return $queued;
    }

    /**
     * Whether any overview summary is currently refreshing.
     *
     * @param  array<string, object>  $summariesByKey
     */
    public function anyRefreshInProgress(array $summariesByKey): bool
    {
        foreach ($summariesByKey as $summary) {
            if (is_object($summary) && ! empty($summary->refreshInProgress)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return Collection<int, DashboardFeed>
     */
    public function collect(): Collection
    {
        return collect($this->feeds);
    }
}
