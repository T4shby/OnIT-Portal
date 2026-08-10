<?php

namespace App\Services\Portal;

use App\Models\Client;
use App\Models\User;
use Carbon\Carbon;

/**
 * Client home + Reports presentation layer over dashboard feeds.
 *
 * States: live | loading | not_sold | setup_needed | platform | cold | pipeline
 * "pipeline" = product sold and API may be live, but this *metric* needs more work
 * (history store, extra Graph scopes, activity audit job, etc.). Never hide gaps.
 */
class ClientHomeOverviewService
{
    public function __construct(
        private DashboardFeedRegistry $feeds,
        private ClientProductService $products,
        private ClientVisibilityService $visibility,
    ) {}

    /**
     * @return array{
     *   mode: string,
     *   client: ?Client,
     *   organisation_wide: bool,
     *   period_label: string,
     *   hero: array{title: string, status_line: string, status_tone: string},
     *   columns: list<array<string, mixed>>,
     *   activity: array{status: string, message: string, items: list<array{at: ?string, source: string, text: string}>},
     *   month_compare: array{status: string, message: string},
     *   portals_available: bool,
     *   generated_at: Carbon
     * }
     */
    public function forUser(User $user): array
    {
        $client = $user->client;
        $orgWide = $client !== null && $this->visibility->canViewOrganisationWide($user, $client);

        $periodLabel = now()->timezone('Europe/London')->format('F Y').', month to date';

        if ($client === null || ! $user->canViewClientAdminDashboard()) {
            return [
                'mode' => 'simple',
                'client' => $client,
                'organisation_wide' => false,
                'period_label' => $periodLabel,
                'hero' => [
                    'title' => 'Your IT at a glance',
                    'status_line' => 'Open portals below or go to My Systems for personal service health.',
                    'status_tone' => 'neutral',
                ],
                'columns' => [],
                'activity' => $this->activityPipelinePlaceholder(),
                'month_compare' => $this->monthComparePlaceholder(),
                'portals_available' => true,
                'generated_at' => now(),
            ];
        }

        $summaries = $this->feeds->summariesForClient($client, false, $user);
        $byKey = $summaries['by_key'];

        $columns = [
            $this->superOpsColumn($client, $user, $byKey['superops'] ?? null, $orgWide),
            $this->m365Column($client, $user, $byKey['m365_insights'] ?? null, $orgWide),
            $this->huntressColumn($client, $user, $byKey['huntress'] ?? null, $orgWide),
            $this->dropsuiteColumn($client, $user, $byKey['dropsuite'] ?? null, $orgWide),
        ];

        $hero = $this->heroFromColumns($client, $columns, $orgWide);

        return [
            'mode' => $orgWide ? 'organisation' : 'personal',
            'client' => $client,
            'organisation_wide' => $orgWide,
            'period_label' => $periodLabel,
            'hero' => $hero,
            'columns' => $columns,
            'activity' => $this->activityPipelinePlaceholder(),
            'month_compare' => $this->monthComparePlaceholder(),
            'portals_available' => true,
            'generated_at' => now(),
            'feed_view' => $summaries['view'],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $columns
     * @return array{title: string, status_line: string, status_tone: string}
     */
    private function heroFromColumns(Client $client, array $columns, bool $orgWide): array
    {
        $attention = 0;
        $live = 0;
        $setup = 0;
        $notSold = 0;

        foreach ($columns as $col) {
            $s = (string) ($col['state'] ?? '');
            if (in_array($s, ['setup_needed', 'platform', 'cold', 'error'], true)) {
                $attention++;
            } elseif ($s === 'live') {
                $live++;
            } elseif ($s === 'not_sold') {
                $notSold++;
            } elseif ($s === 'loading') {
                $attention++;
            }
        }

        if ($attention > 0) {
            return [
                'title' => 'Your IT at a glance',
                'status_line' => $attention === 1
                    ? 'Attention needed on 1 service.'
                    : "Attention needed on {$attention} services.",
                'status_tone' => 'warn',
            ];
        }

        if ($live > 0) {
            return [
                'title' => 'Your IT at a glance',
                'status_line' => 'All systems protected.',
                'status_tone' => 'ok',
            ];
        }

        return [
            'title' => 'Your IT at a glance',
            'status_line' => 'Services not live yet.',
            'status_tone' => 'neutral',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function superOpsColumn(Client $client, User $user, mixed $summary, bool $orgWide): array
    {
        $base = $this->baseColumn(
            key: 'superops',
            title: 'Support & Devices',
            plan_label: 'Managed IT Support',
            source: 'SuperOps',
            href: $orgWide ? route('client-admin.dashboard') : route('support.index'),
            href_label: $orgWide ? 'Organisation detail →' : 'View tickets →',
            client: $client,
            user: $user,
        );

        if ($base['state'] !== 'live_candidate') {
            return $base;
        }

        if (! is_object($summary)) {
            return $this->markState($base, 'cold', 'No SuperOps snapshot yet — waiting for auto-refresh.');
        }

        if (! empty($summary->refreshInProgress) && ! method_exists($summary, 'hasData')) {
            return $this->markState($base, 'loading', 'Refreshing SuperOps metrics…');
        }

        if (method_exists($summary, 'hasData') && ! $summary->hasData()) {
            $reason = (string) ($summary->unavailableReason ?? 'SuperOps data unavailable.');
            if (str_contains(strtolower($reason), 'not connected') || str_contains(strtolower($reason), 'not sold')) {
                return $this->markState($base, 'setup_needed', $reason);
            }
            if (str_contains(strtolower($reason), 'not configured')) {
                return $this->markState($base, 'platform', $reason);
            }
            if (! empty($summary->refreshInProgress)) {
                return $this->markState($base, 'loading', 'Loading SuperOps metrics…');
            }

            return $this->markState($base, 'cold', $reason);
        }

        $closed30 = is_array($summary->ticketsClosed ?? null)
            ? ($summary->ticketsClosed['30'] ?? null)
            : null;

        $metrics = [
            $this->metric('Open tickets', $summary->openTicketsTotal, null),
            $this->pipelineMetric(
                'Awaiting your reply',
                '“Waiting on client” ticket count is not broken out in the SuperOps snapshot yet.',
            ),
            $this->metric('Resolved this month', $closed30, null),
            $this->pipelineMetric(
                'Avg first response',
                'First-response average vs 30m SLA is not computed in the portal yet.',
            ),
            $this->metric('Devices managed', $summary->assetsTotal, null),
            $this->metric('Healthy', $summary->assetsOnline, null),
            $this->pipelineMetric(
                'Need updates',
                'Patch/update posture is not in the SuperOps snapshot yet.',
            ),
            $this->metric('Offline', $summary->assetsOffline, null),
            $this->metric(
                'SLA met',
                $summary->slaMetPercent !== null ? $summary->slaMetPercent.'%' : null,
                $summary->slaSampleSize !== null ? 'sample '.$summary->slaSampleSize : null,
            ),
        ];

        $tone = 'ok';
        if (($summary->openTicketsTotal ?? 0) > 10 || ($summary->assetsOffline ?? 0) > 0) {
            $tone = 'warn';
        }

        return array_merge($base, [
            'state' => 'live',
            'tone' => $tone,
            'status_label' => ($summary->openTicketsTotal ?? 0) === 0 ? 'On track' : 'Open tickets',
            'message' => null,
            'metrics' => $metrics,
            'as_of' => $summary->lastRefreshedAt,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function m365Column(Client $client, User $user, mixed $summary, bool $orgWide): array
    {
        $base = $this->baseColumn(
            key: 'm365',
            title: 'Microsoft 365',
            plan_label: 'Microsoft 365 Management',
            source: 'Microsoft Graph',
            href: route('microsoft-365.directory'),
            href_label: 'View tenant →',
            client: $client,
            user: $user,
        );

        // Personal users: m365 licence tile often hidden; still show directory link if entitled.
        if (! $orgWide) {
            $st = $this->products->status($client, 'm365');
            if ($st === ClientProductService::STATUS_NOT_SOLD) {
                return $this->markState($base, 'not_sold', 'Microsoft 365 is not sold for this organisation.');
            }
            if ($st === ClientProductService::STATUS_SETUP_NEEDED) {
                return $this->markState($base, 'setup_needed', 'Microsoft 365 tenant link is not finished — contact your account manager.');
            }

            return array_merge($base, [
                'state' => 'live',
                'tone' => 'neutral',
                'status_label' => 'Directory',
                'message' => null,
                'metrics' => [
                    $this->pipelineMetric('Licence fleet', 'Organisation licence insight is for Client Admins.'),
                    $this->pipelineMetric('Secure Score', 'Needs Microsoft Graph Secure Score permission + feed — not set up yet.'),
                    $this->pipelineMetric('MFA coverage', 'Needs Graph authentication methods / registration reports — not set up yet.'),
                ],
                'as_of' => null,
            ]);
        }

        if ($base['state'] !== 'live_candidate') {
            return $base;
        }

        if (! is_object($summary)) {
            return $this->markState($base, 'cold', 'No Microsoft 365 licence snapshot yet — waiting for auto-refresh.');
        }

        $reason = (string) ($summary->unavailableReason ?? '');
        if ($reason !== '' && ($summary->lastRefreshedAt ?? null) === null) {
            if (! empty($summary->refreshInProgress)) {
                return $this->markState($base, 'loading', 'Loading Microsoft 365 metrics…');
            }
            if (str_contains(strtolower($reason), 'not configured') || str_contains(strtolower($reason), 'tenant')) {
                return $this->markState($base, 'setup_needed', $reason);
            }

            return $this->markState($base, 'cold', $reason !== '' ? $reason : 'Microsoft 365 data unavailable.');
        }

        $topPlan = null;
        if (is_array($summary->topSkus ?? null) && $summary->topSkus !== []) {
            $first = $summary->topSkus[0] ?? null;
            $topPlan = is_array($first)
                ? ($first['name'] ?? $first['skuPartNumber'] ?? null)
                : null;
        }

        $metrics = [
            $this->metric(
                'Licences assigned',
                $summary->totalSeatsAssigned ?? null,
                $summary->totalSeatsPurchased !== null ? 'of '.$summary->totalSeatsPurchased : null,
            ),
            $this->metric('Plan', $topPlan, null),
            $this->pipelineMetric(
                'Secure Score',
                'Requires Graph Secure Score + app consent per tenant — not set up yet.',
            ),
            $this->pipelineMetric(
                'MFA coverage',
                'Requires Graph authentication methods / registration reports — not set up yet.',
            ),
            $this->pipelineMetric(
                'Without MFA',
                'Blocked until MFA coverage feed exists.',
            ),
            $this->metric(
                'Utilisation',
                $summary->overallUtilizationPct !== null ? round($summary->overallUtilizationPct).'%' : null,
                null,
            ),
            $this->metric('Licensed users', $summary->licensedUserCount ?? null, 'User mailboxes only'),
        ];

        return array_merge($base, [
            'state' => 'live',
            'tone' => 'ok',
            'status_label' => 'Licences',
            'message' => null,
            'metrics' => $metrics,
            'as_of' => $summary->lastRefreshedAt,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function huntressColumn(Client $client, User $user, mixed $summary, bool $orgWide): array
    {
        $base = $this->baseColumn(
            key: 'huntress',
            title: 'Detection & Response',
            plan_label: 'Managed Cybersecurity (MDR + ITDR)',
            source: 'Huntress',
            href: route('security.huntress.index'),
            href_label: 'View cases →',
            client: $client,
            user: $user,
        );

        if ($base['state'] !== 'live_candidate') {
            return $base;
        }

        if (! is_object($summary)) {
            return $this->markState($base, 'cold', 'No Huntress snapshot yet — waiting for auto-refresh.');
        }

        if (empty($summary->available) && empty($summary->lastRefreshedAt)) {
            $reason = (string) ($summary->unavailableReason ?? 'Huntress data unavailable.');
            if (! empty($summary->refreshInProgress)) {
                return $this->markState($base, 'loading', 'Loading Huntress metrics…');
            }
            if (str_contains(strtolower($reason), 'not linked') || str_contains(strtolower($reason), 'not sold') || str_contains(strtolower($reason), 'setup')) {
                return $this->markState($base, 'setup_needed', $reason);
            }

            return $this->markState($base, 'cold', $reason);
        }

        $metrics = [
            $this->metric(
                'Agent coverage',
                $summary->agentsTotal,
                null,
            ),
            $this->metric('24/7 monitoring', $summary->agentsTotal !== null ? 'Active' : null, null),
            $this->pipelineMetric(
                'Incidents this month',
                'MTD incident total needs period query — open/resolved below are snapshot totals.',
            ),
            $this->metric('Open incidents', $summary->openIncidents, null),
            $this->metric('Remediated (snapshot)', $summary->resolvedIncidents, null),
            $this->metric('Unresponsive agents', $summary->agentsUnresponsive, null),
            $this->pipelineMetric(
                'Identity (ITDR) alerts',
                'ITDR alert parsing is not in the Huntress metrics feed yet.',
            ),
            $this->pipelineMetric(
                'Outcome',
                'ITDR narrative outcomes not set up yet.',
            ),
        ];

        $open = (int) ($summary->openIncidents ?? 0);
        $tone = $open > 0 ? 'warn' : 'ok';

        return array_merge($base, [
            'state' => 'live',
            'tone' => $tone,
            'status_label' => $open > 0 ? 'Incidents open' : 'Protected',
            'message' => null,
            'metrics' => $metrics,
            'as_of' => $summary->lastRefreshedAt,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function dropsuiteColumn(Client $client, User $user, mixed $summary, bool $orgWide): array
    {
        $base = $this->baseColumn(
            key: 'dropsuite',
            title: 'Backup',
            plan_label: 'Email & Data Backup',
            source: 'Dropsuite',
            href: $orgWide && $user->can('view-organisation-wide')
                ? route('client-admin.backups')
                : route('client-admin.dashboard'),
            href_label: $orgWide ? 'Online backups →' : 'My systems →',
            client: $client,
            user: $user,
        );

        if ($base['state'] !== 'live_candidate') {
            return $base;
        }

        if (! is_object($summary)) {
            return $this->markState($base, 'cold', 'No Dropsuite snapshot yet — waiting for auto-refresh.');
        }

        if (empty($summary->available) || (method_exists($summary, 'hasData') && ! $summary->hasData() && empty($summary->lastRefreshedAt))) {
            $reason = (string) ($summary->unavailableReason ?? 'Backup data unavailable.');
            if (! empty($summary->refreshInProgress)) {
                return $this->markState($base, 'loading', 'Loading backup metrics…');
            }
            if (str_contains(strtolower($reason), 'not') || str_contains(strtolower($reason), 'setup')) {
                return $this->markState($base, 'setup_needed', $reason !== '' ? $reason : 'Backup product needs mapping.');
            }

            return $this->markState($base, 'cold', $reason !== '' ? $reason : 'Backup metrics cold.');
        }

        $failed = $summary->failedLast24h ?? $summary->failedBackupsCount ?? null;
        $metrics = [
            $this->metric('Mailboxes protected', $summary->protectedMailboxes, null),
            $this->metric(
                'SharePoint & OneDrive',
                (($summary->onedriveCount ?? 0) + ($summary->sharepointCount ?? 0)) > 0
                    ? 'Included'
                    : (($summary->onedriveCount === null && $summary->sharepointCount === null) ? null : 'Mapped'),
                null,
            ),
            $this->metric(
                'Last backup run',
                $summary->lastBackupAt?->timezone('Europe/London')->format('d M H:i')
                    ?? ($summary->lastBackupStatus ?: null),
                null,
            ),
            $this->metric('Succeeded', $summary->succeededLast24h, 'last 24h'),
            $this->metric('Retrying', $failed, $failed ? 'open issues' : null),
            $this->pipelineMetric(
                'Restore points kept',
                'Retention days are not in the Dropsuite metrics payload — not set up yet.',
            ),
        ];

        $tone = ((int) $failed) > 0 ? 'warn' : 'ok';

        return array_merge($base, [
            'state' => 'live',
            'tone' => $tone,
            'status_label' => ((int) $failed) > 0 ? 'Retries in progress' : 'Protected',
            'message' => null,
            'metrics' => $metrics,
            'as_of' => $summary->lastRefreshedAt,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function baseColumn(
        string $key,
        string $title,
        string $plan_label,
        string $source,
        string $href,
        string $href_label,
        Client $client,
        User $user,
    ): array {
        $productKey = $key === 'm365' ? 'm365' : $key;
        $status = $this->products->status($client, $productKey);

        $column = [
            'key' => $key,
            'title' => $title,
            'plan_label' => $plan_label,
            'source' => $source,
            'href' => $href,
            'href_label' => $href_label,
            'state' => 'live_candidate',
            'tone' => 'neutral',
            'status_label' => '—',
            'message' => null,
            'metrics' => [],
            'as_of' => null,
            'product_status' => $status,
        ];

        // Requesters must not see not-sold upsell the same way; hide column if not shown for viewer.
        if (! $this->products->shouldShowOverviewTile($client, $productKey === 'm365' ? 'm365_insights' : $productKey, $user)
            && ! $this->products->shouldShowForViewer($client, $productKey, $user)
            && $status === ClientProductService::STATUS_NOT_SOLD
            && ! $user->isClientAdmin()) {
            return $this->markState($column, 'hidden', 'Not available for this user.');
        }

        if ($status === ClientProductService::STATUS_NOT_SOLD) {
            return $this->markState(
                $column,
                'not_sold',
                'Not sold for this organisation. Contact your account manager if you want this enabling.',
            );
        }

        if ($status === ClientProductService::STATUS_SETUP_NEEDED) {
            return $this->markState(
                $column,
                'setup_needed',
                'Sold but not fully linked (mapping ID / tenant). Contact your account manager — technicians will finish setup.',
            );
        }

        if ($status === ClientProductService::STATUS_PLATFORM_DOWN) {
            return $this->markState(
                $column,
                'platform',
                'On IT platform credentials for this product are off or incomplete. Staff must enable in portal config.',
            );
        }

        if ($status === ClientProductService::STATUS_ERROR) {
            return $this->markState(
                $column,
                'error',
                'Recent refresh failed for this product. Staff: check Integration Health last_result; map ID and credentials.',
            );
        }

        return $column;
    }

    /**
     * @param  array<string, mixed>  $column
     * @return array<string, mixed>
     */
    private function markState(array $column, string $state, string $message): array
    {
        $tone = match ($state) {
            'live' => 'ok',
            'loading' => 'neutral',
            'not_sold' => 'muted',
            'setup_needed', 'platform', 'cold', 'error', 'pipeline' => 'warn',
            default => 'neutral',
        };

        $label = match ($state) {
            'not_sold' => 'Not sold',
            'setup_needed' => 'Setup needed',
            'platform' => 'Platform off',
            'cold' => 'Never loaded',
            'loading' => 'Loading',
            'hidden' => 'Hidden',
            default => 'Attention',
        };

        return array_merge($column, [
            'state' => $state,
            'tone' => $tone,
            'status_label' => $label,
            'message' => $message,
            'metrics' => $column['metrics'] ?? [],
        ]);
    }

    /**
     * @return array{label: string, value: string, hint: ?string, kind: string, suffix: ?string}
     */
    private function metric(string $label, mixed $value, ?string $hint): array
    {
        if ($value === null || $value === '') {
            return [
                'label' => $label,
                'value' => '—',
                'hint' => $hint ?? 'No value in latest snapshot',
                'kind' => 'empty',
                'suffix' => null,
            ];
        }

        return [
            'label' => $label,
            'value' => is_bool($value) ? ($value ? 'Yes' : 'No') : (string) $value,
            'hint' => null,
            'kind' => 'ok',
            'suffix' => $hint,
        ];
    }

    /**
     * @return array{label: string, value: string, hint: ?string, kind: string, suffix: ?string}
     */
    private function pipelineMetric(string $label, string $reason): array
    {
        return [
            'label' => $label,
            'value' => 'Not set up',
            'hint' => $reason,
            'kind' => 'pipeline',
            'suffix' => null,
        ];
    }

    /**
     * @return array{status: string, message: string, items: list<array{at: ?string, source: string, text: string}>}
     */
    private function activityPipelinePlaceholder(): array
    {
        return [
            'status' => 'pipeline',
            'message' => 'Activity timeline (“What we’ve done for you”) is not set up yet. Needs a cross-product event pipeline (Huntress remediations, SuperOps ticket events, backup outcomes). Come back after that job exists — do not treat blank as “nothing happened”.',
            'items' => [],
        ];
    }

    /**
     * @return array{status: string, message: string}
     */
    private function monthComparePlaceholder(): array
    {
        return [
            'status' => 'pipeline',
            'message' => 'Month-to-date vs last month comparisons need retained daily snapshots. Not set up yet — current numbers are live snapshots only.',
        ];
    }
}
