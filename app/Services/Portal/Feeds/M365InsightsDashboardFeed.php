<?php

namespace App\Services\Portal\Feeds;

use App\Contracts\DashboardFeed;
use App\Models\Client;
use App\Services\M365\M365InsightsService;

class M365InsightsDashboardFeed implements DashboardFeed
{
    public function __construct(private M365InsightsService $metrics) {}

    public function key(): string
    {
        return 'm365_insights';
    }

    public function label(): string
    {
        return 'Microsoft 365 licences';
    }

    public function prewarmPriority(): string
    {
        return 'optional';
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function isLinked(Client $client): bool
    {
        return filled($client->entra_tenant_id);
    }

    public function summaryForClient(Client $client, bool $manualRefresh = false): object
    {
        return $this->metrics->summaryForClient($client, $manualRefresh);
    }

    public function needsBackgroundRefresh(Client $client): bool
    {
        return $this->metrics->needsBackgroundRefresh($client);
    }

    public function queueRefresh(Client $client, bool $respectCooldown = false): bool
    {
        return $this->metrics->queueRefresh($client, $respectCooldown);
    }

    public function overviewPartial(): ?string
    {
        return 'client-admin.feeds._m365-insights';
    }

    public function summaryViewVar(): string
    {
        return 'm365Insights';
    }
}
