<?php

namespace App\Contracts;

use App\Models\Client;

/**
 * Modular Client Admin / prewarm feed (SuperOps, M365, Huntress, Dropsuite, …).
 *
 * Implementations are registered once in AppServiceProvider (DashboardFeedRegistry).
 * System-health tiles that declare overviewPartial() are auto-rendered; SuperOps-owned
 * sections (tickets, SLA) stay SuperOps-specific in the main metrics blade.
 *
 * @see Brain/ClientAdminDashboard.md — "Dashboard feed contract"
 */
interface DashboardFeed
{
    /** Machine key: superops, huntress, dropsuite, m365_insights, m365_directory */
    public function key(): string;

    /** Short human label for logs / health */
    public function label(): string;

    /**
     * critical = always prewarm when due (even under deep queue).
     * optional = only when jobs table is under capacity.
     *
     * @return 'critical'|'optional'
     */
    public function prewarmPriority(): string;

    public function isAvailable(): bool;

    public function isLinked(Client $client): bool;

    /**
     * Typed summary DTO (hasData, lastRefreshedAt, isStale, refreshInProgress, …).
     */
    public function summaryForClient(Client $client, bool $manualRefresh = false): object;

    public function needsBackgroundRefresh(Client $client): bool;

    public function queueRefresh(Client $client, bool $respectCooldown = false): bool;

    /**
     * Blade partial for the System health grid, or null if not a tile
     * (e.g. M365 directory is a separate page; SuperOps tickets stay in main blade).
     */
    public function overviewPartial(): ?string;

    /**
     * Variable name expected by dashboard blades for BC (summary, huntressSummary, …).
     */
    public function summaryViewVar(): string;
}
