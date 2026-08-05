<?php

namespace App\Services\Portal\Feeds;

use App\Contracts\DashboardFeed;
use App\Models\Client;
use App\Services\Dropsuite\DropsuiteClientMetricsService;

class DropsuiteDashboardFeed implements DashboardFeed
{
    public function __construct(private DropsuiteClientMetricsService $metrics) {}

    public function key(): string
    {
        return 'dropsuite';
    }

    public function label(): string
    {
        return 'Dropsuite backups';
    }

    public function prewarmPriority(): string
    {
        return 'optional';
    }

    public function isAvailable(): bool
    {
        return $this->metrics->isAvailable();
    }

    public function isLinked(Client $client): bool
    {
        return filled($client->dropsuite_organization_id);
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
        return 'client-admin.feeds._dropsuite';
    }

    public function summaryViewVar(): string
    {
        return 'dropsuiteSummary';
    }
}
