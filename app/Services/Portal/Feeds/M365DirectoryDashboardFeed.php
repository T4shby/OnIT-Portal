<?php

namespace App\Services\Portal\Feeds;

use App\Contracts\DashboardFeed;
use App\Models\Client;
use App\Services\M365\M365DirectoryService;

/**
 * Optional prewarm-only feed; System health tile is M365 insights; directory has its own page.
 */
class M365DirectoryDashboardFeed implements DashboardFeed
{
    public function __construct(private M365DirectoryService $metrics) {}

    public function key(): string
    {
        return 'm365_directory';
    }

    public function label(): string
    {
        return 'Microsoft 365 directory';
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
        // Not used on organisation overview.
        return (object) [
            'hasData' => false,
            'refreshInProgress' => false,
            'lastRefreshedAt' => null,
            'isStale' => false,
        ];
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
        return null;
    }

    public function summaryViewVar(): string
    {
        return 'm365Directory';
    }
}
