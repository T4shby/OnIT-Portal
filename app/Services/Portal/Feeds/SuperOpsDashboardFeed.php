<?php

namespace App\Services\Portal\Feeds;

use App\Contracts\DashboardFeed;
use App\Models\Client;
use App\Services\SuperOps\SuperOpsClientMetricsService;

class SuperOpsDashboardFeed implements DashboardFeed
{
    public function __construct(private SuperOpsClientMetricsService $metrics) {}

    public function key(): string
    {
        return 'superops';
    }

    public function label(): string
    {
        return 'SuperOps devices & tickets';
    }

    public function prewarmPriority(): string
    {
        return 'critical';
    }

    public function isAvailable(): bool
    {
        return $this->metrics->isAvailable();
    }

    public function isLinked(Client $client): bool
    {
        return filled($client->superops_account_id);
    }

    public function summaryForClient(Client $client, bool $manualRefresh = false): object
    {
        return $this->metrics->summaryForClient($client, $manualRefresh);
    }

    public function needsBackgroundRefresh(Client $client): bool
    {
        return $this->metrics->needsColdPrewarm($client)
            || $this->metrics->needsBackgroundRefresh($client);
    }

    public function queueRefresh(Client $client, bool $respectCooldown = false): bool
    {
        return $this->metrics->queueRefresh($client, $respectCooldown);
    }

    public function overviewPartial(): ?string
    {
        return 'client-admin.feeds._superops-devices';
    }

    public function summaryViewVar(): string
    {
        return 'summary';
    }
}
