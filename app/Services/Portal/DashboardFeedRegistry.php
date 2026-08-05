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
    public function summariesForClient(Client $client, bool $manualRefresh = false): array
    {
        $byKey = [];
        $view = [];

        foreach ($this->feeds as $feed) {
            // M365 directory is not loaded on overview (own page).
            if ($feed->key() === 'm365_directory') {
                continue;
            }

            $summary = $feed->summaryForClient($client, $manualRefresh);
            $byKey[$feed->key()] = $summary;
            $view[$feed->summaryViewVar()] = $summary;
        }

        return [
            'by_key' => $byKey,
            'view' => $view,
        ];
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
