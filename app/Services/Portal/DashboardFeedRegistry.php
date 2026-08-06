<?php

namespace App\Services\Portal;

use App\Contracts\DashboardFeed;
use App\Models\Client;
use Illuminate\Support\Collection;

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

            // Regular users only see systems relevant to them — org backup/licence fleet is admin.
            if (! $orgWide && in_array($feed->key(), ['dropsuite', 'm365_insights'], true)) {
                continue;
            }

            if ($feed->key() === 'superops' && method_exists($feed, 'summaryForClient')) {
                // Adapter delegates; metrics service accepts viewer via metrics call below...
            }

            $summary = match ($feed->key()) {
                'superops' => app(\App\Services\SuperOps\SuperOpsClientMetricsService::class)
                    ->summaryForClient($client, $manualRefresh, $viewer),
                'huntress' => $this->huntressSummaryForViewer($client, $manualRefresh, $viewer, $orgWide),
                default => $feed->summaryForClient($client, $manualRefresh),
            };

            $byKey[$feed->key()] = $summary;
            $view[$feed->summaryViewVar()] = $summary;
        }

        // Ensure BC vars exist when feeds skipped for personal scope.
        if (! isset($view['dropsuiteSummary'])) {
            $view['dropsuiteSummary'] = new \App\Services\Dropsuite\DropsuiteClientBackupSummary(
                protectedMailboxes: null,
                lastBackupStatus: 'unknown',
                failedBackupsCount: null,
                available: false,
                unavailableReason: 'Organisation backup health is available to Client Admins.',
                lastRefreshedAt: null,
                isStale: false,
                refreshInProgress: false,
            );
        }
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
