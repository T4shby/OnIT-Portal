<?php

namespace App\Services\Portal;

use App\Models\Client;
use App\Models\User;
use App\Services\Huntress\HuntressIncidentService;
use Carbon\Carbon;

/**
 * Client home + Reports presentation layer over dashboard feeds.
 *
 * Column traffic lights (live services):
 *   tone ok/green   → Healthy
 *   tone warn/yellow → Issues
 *   tone bad/red    → Critical
 *
 * Product not ready: setup/cold/loading → Issues (warn); platform/error → Critical (bad).
 * Per-feed bands are documented in Brain/UIOverhaul.md and on the private health* methods.
 */
class ClientHomeOverviewService
{
    public function __construct(
        private DashboardFeedRegistry $feeds,
        private ClientProductService $products,
        private ClientVisibilityService $visibility,
        private ClientActivityFeedService $activity,
        private ClientMetricSnapshotService $snapshots,
        private HuntressIncidentService $huntressIncidents,
    ) {}

    /**
     * @return array{
     *   mode: string,
     *   client: ?Client,
     *   organisation_wide: bool,
     *   period_label: string,
     *   hero: array{title: string, status_line: string, status_tone: string},
     *   columns: list<array<string, mixed>>,
     *   activity: array{status: string, message: string, items: list<array{at: ?string, source: string, text: string, title?: string}>},
     *   month_compare: array{status: string, message: string, available?: bool, as_of?: ?string, value?: ?array, services?: array},
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
                'activity' => $this->emptyActivity('Sign in with a client workspace to see recent work.'),
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
            'activity' => $this->activityFor($client, $user),
            'month_compare' => $this->monthCompareFor($client, $columns, $hero),
            'portals_available' => true,
            'generated_at' => now(),
            'feed_view' => $summaries['view'],
        ];
    }

    /**
     * Staff-facing: what this client’s home (/dashboard) and Reports show, given product entitlements.
     * Does not load live feed numbers - only sold/setup state so technicians can explain differences
     * between customers (e.g. support-only vs MDR included) without inventing metrics.
     *
     * @return array{
     *   value_strip_mode: 'support_led'|'mdr_included',
     *   value_strip_labels: list<string>,
     *   value_strip_summary: string,
     *   headline: string,
     *   detail: string,
     *   bullets: list<string>,
     *   columns: list<array{
     *     key: string,
     *     title: string,
     *     status: string,
     *     staff_status_label: string,
     *     client_card_label: string,
     *     client_reason: ?string
     *   }>
     * }
     */
    public function staffHomeComposition(Client $client): array
    {
        $columnMeta = [
            'superops' => 'Support & Devices',
            'm365' => 'Microsoft 365',
            'huntress' => 'Security',
            'dropsuite' => 'Backup',
        ];

        $columns = [];
        foreach ($columnMeta as $key => $title) {
            $status = $this->products->status($client, $key);
            $staffLabel = $this->products->statusLabel($status, $key);
            $clientLabel = $staffLabel;
            $clientReason = null;

            if ($status === ClientProductService::STATUS_NOT_SOLD) {
                $notSold = $this->notSoldPresentation($key);
                $clientLabel = $notSold['label'];
                $clientReason = $notSold['reason'];
            } elseif ($status === ClientProductService::STATUS_SETUP_NEEDED) {
                $clientLabel = 'Setup needed';
                $clientReason = 'Sold and on the plan - mapping or tenant link still needed before numbers appear.';
            } elseif ($status === ClientProductService::STATUS_PLATFORM_DOWN) {
                $clientLabel = 'Critical';
                $clientReason = 'Platform credentials disabled or incomplete on the On IT side.';
            } elseif ($status === ClientProductService::STATUS_ERROR) {
                $clientLabel = 'Critical';
                $clientReason = 'Last refresh failed; staff should check Integration Health.';
            } else {
                $clientLabel = 'Healthy (when feed is live)';
                $clientReason = 'When the feed is live, traffic lights use operational rules (SLA, seats, incidents, backup failures).';
            }

            $columns[] = [
                'key' => $key,
                'title' => $title,
                'status' => $status,
                'staff_status_label' => $staffLabel,
                'client_card_label' => $clientLabel,
                'client_reason' => $clientReason,
            ];
        }

        $huntressSold = $this->products->isEntitled($client, ClientProductService::KEY_HUNTRESS);
        $mode = $huntressSold ? 'mdr_included' : 'support_led';

        if ($mode === 'support_led') {
            $labels = ['Tickets resolved', 'Open tickets', 'SLA met'];
            $headline = 'Support-led home (Huntress MDR not sold)';
            $summary = 'Hero stats: Tickets resolved · Open tickets · SLA met. No “Threats stopped” tile.';
            $detail = 'This is intentional. Empty MDR metrics would look like “we stop no threats.” '
                .'Support tickets and technicians remain how On IT handles security for this organisation. '
                .'Security shows as an optional Add-on, not unprotected.';
            $bullets = [
                'Turning Huntress sold + mapping the org ID switches hero stats to include Threats stopped (via MDR).',
                'Grey H on the Clients list means not sold - the client home stays support-led, not “broken AV.”',
                'Never invent threat counts from SuperOps tickets - that would mislabel ticket work as MDR.',
            ];
        } else {
            $labels = ['Threats stopped (MDR)', 'Tickets resolved', 'SLA met'];
            $headline = 'MDR + support home (Huntress sold)';
            $summary = 'Hero stats: Threats stopped · Tickets resolved · SLA met (threats may show - until the Huntress feed is live).';
            $detail = 'Client sees automated Huntress metrics when the feed is live. '
                .'If the feed is cold or setup is incomplete, threats can be blank - finish mapping and Integration Health, do not invent figures.';
            $bullets = [
                'Sold but not mapped = Setup needed on the client home (amber), not Add-on.',
                'Live feed zero threats is fine; a dash only while loading/cold is OK - not when product is not sold.',
                'Untick Huntress sold only if they are truly off that product (reverts home to support-led).',
            ];
        }

        return [
            'value_strip_mode' => $mode,
            'value_strip_labels' => $labels,
            'value_strip_summary' => $summary,
            'headline' => $headline,
            'detail' => $detail,
            'bullets' => $bullets,
            'columns' => $columns,
        ];
    }

    /**
     * Org-facing metrics bundle for nightly snapshots (uses a privileged client user).
     *
     * @return array{overall_band: ?string, columns: list<array<string, mixed>>, value: array<string, mixed>}
     */
    public function snapshotBundleForClient(Client $client, User $user): array
    {
        $overview = $this->forUser($user);
        $columns = $overview['columns'] ?? [];
        $value = $this->valueStripFromColumns($columns);
        $tone = (string) ($overview['hero']['status_tone'] ?? 'neutral');

        return [
            'overall_band' => $tone,
            'columns' => $columns,
            'value' => $value,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $columns
     * @return array{threats: ?string, resolved: ?string, sla: ?string}
     */
    public function valueStripFromColumns(array $columns): array
    {
        $byKey = [];
        foreach ($columns as $col) {
            if (is_array($col) && filled($col['key'] ?? null)) {
                $byKey[(string) $col['key']] = $col;
            }
        }

        return [
            'threats' => $this->okMetricValue($byKey['huntress'] ?? null, [
                'Threats stopped (MTD)',
                'Remediated',
                'Remediated (snapshot)',
                'Resolved incidents',
            ]),
            'resolved' => $this->okMetricValue($byKey['superops'] ?? null, [
                'Resolved this month',
                'Resolved (30d)',
            ]),
            'sla' => $this->okMetricValue($byKey['superops'] ?? null, ['SLA met']),
        ];
    }

    /**
     * @param  list<string>  $labels
     */
    private function okMetricValue(?array $col, array $labels): ?string
    {
        if ($col === null) {
            return null;
        }
        foreach ($col['metrics'] ?? [] as $m) {
            if (($m['kind'] ?? '') !== 'ok') {
                continue;
            }
            if (in_array($m['label'] ?? '', $labels, true)) {
                return (string) $m['value'];
            }
        }

        return null;
    }

    /**
     * @return array{status: string, message: string, items: list<array{at: ?string, source: string, text: string, title?: string}>}
     */
    private function activityFor(Client $client, User $user): array
    {
        $rows = $this->activity->recentFor($client, $user, 8);
        if ($rows === []) {
            return $this->emptyActivity('No recent support or security activity in the latest snapshots.');
        }

        $items = [];
        foreach ($rows as $row) {
            $items[] = [
                'at' => $row['at'] ?? null,
                'source' => $row['source'] ?? 'portal',
                'title' => $row['title'] ?? null,
                'text' => $row['detail'] ?? '',
            ];
        }

        return [
            'status' => 'live',
            'message' => 'Recent activity from support tickets, security cases, and backup attention items.',
            'items' => $items,
        ];
    }

    /**
     * @return array{status: string, message: string, items: list}
     */
    private function emptyActivity(string $message): array
    {
        return [
            'status' => 'empty',
            'message' => $message,
            'items' => [],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $columns
     * @param  array<string, mixed>  $hero
     * @return array<string, mixed>
     */
    private function monthCompareFor(Client $client, array $columns, array $hero): array
    {
        $compare = $this->snapshots->previousMonthCompare($client);
        if (! ($compare['available'] ?? false)) {
            return $compare;
        }

        $currentValue = $this->valueStripFromColumns($columns);
        $priorValue = is_array($compare['value'] ?? null) ? $compare['value'] : [];

        $deltas = [];
        foreach (['threats' => 'Threats stopped', 'resolved' => 'Tickets resolved', 'sla' => 'SLA met'] as $key => $label) {
            $cur = $priorValue !== [] ? ($currentValue[$key] ?? null) : ($currentValue[$key] ?? null);
            $prev = $priorValue[$key] ?? null;
            if ($cur === null && $prev === null) {
                continue;
            }
            $deltas[] = [
                'label' => $label,
                'current' => $cur,
                'previous' => $prev,
            ];
        }

        $compare['value_deltas'] = $deltas;
        $compare['current_value'] = $currentValue;
        $compare['current_band'] = $hero['status_tone'] ?? null;

        return $compare;
    }

    /**
     * Hero takes the worst traffic light across sold live services (and product setup failures).
     *
     * @param  list<array<string, mixed>>  $columns
     * @return array{title: string, status_line: string, status_tone: string, status_detail: ?string}
     */
    private function heroFromColumns(Client $client, array $columns, bool $orgWide): array
    {
        $live = 0;
        $critical = 0;
        $issues = 0;
        $setupAttention = 0;
        $firstCriticalWhy = null;
        $firstIssuesWhy = null;

        foreach ($columns as $col) {
            $s = (string) ($col['state'] ?? '');
            $tone = (string) ($col['tone'] ?? 'neutral');
            $why = $this->clientFacingWhy($col);

            if ($s === 'not_sold' || $s === 'hidden') {
                continue;
            }

            if (in_array($s, ['setup_needed', 'platform', 'cold', 'error', 'loading'], true)) {
                $setupAttention++;
                if ($tone === 'bad' || in_array($s, ['platform', 'error'], true)) {
                    $critical++;
                    $firstCriticalWhy ??= $why;
                } else {
                    $issues++;
                    $firstIssuesWhy ??= $why;
                }

                continue;
            }

            if ($s === 'live') {
                $live++;
                if ($tone === 'bad') {
                    $critical++;
                    $firstCriticalWhy ??= $why;
                } elseif ($tone === 'warn') {
                    $issues++;
                    $firstIssuesWhy ??= $why;
                }
            }
        }

        if ($critical > 0) {
            return [
                'title' => 'Your IT at a glance',
                'status_line' => $critical === 1
                    ? '1 service needs critical attention.'
                    : "{$critical} services need critical attention.",
                'status_tone' => 'bad',
                'status_detail' => $firstCriticalWhy,
            ];
        }

        if ($issues > 0 || $setupAttention > 0) {
            $n = max($issues, $setupAttention);

            return [
                'title' => 'Your IT at a glance',
                'status_line' => $n === 1
                    ? '1 service has issues to review.'
                    : "{$n} services have issues to review.",
                'status_tone' => 'warn',
                'status_detail' => $firstIssuesWhy,
            ];
        }

        if ($live > 0) {
            return [
                'title' => 'Your IT at a glance',
                'status_line' => 'All systems protected.',
                'status_tone' => 'ok',
                'status_detail' => 'Every connected service is healthy right now.',
            ];
        }

        return [
            'title' => 'Your IT at a glance',
            'status_line' => 'Services not live yet.',
            'status_tone' => 'neutral',
            'status_detail' => null,
        ];
    }

    /**
     * Plain-English “why this colour” for client admins (not tech staff jargon).
     */
    private function clientFacingWhy(array $col): ?string
    {
        $reason = trim((string) ($col['status_reason'] ?? ''));
        if ($reason !== '') {
            return $reason;
        }

        $message = trim((string) ($col['message'] ?? ''));

        return $message !== '' ? $message : null;
    }

    /**
     * Traffic-light band for live service health.
     *
     * @return array{tone: string, status_label: string, status_reason: string}
     */
    private function healthBand(string $band, string $reason): array
    {
        return match ($band) {
            'critical' => ['tone' => 'bad', 'status_label' => 'Critical', 'status_reason' => $reason],
            'issues' => ['tone' => 'warn', 'status_label' => 'Issues', 'status_reason' => $reason],
            default => ['tone' => 'ok', 'status_label' => 'Healthy', 'status_reason' => $reason],
        };
    }

    private function countPhrase(int $n, string $one, string $many): string
    {
        return $n === 1 ? "1 {$one}" : "{$n} {$many}";
    }

    /**
     * SuperOps live health - client-facing, not presence/agent-online.
     *
     * Devices are ignored: many customers have on-site / offline-by-design kit
     * (field engineers, plant, night shutdown), so “offline” is noise not health.
     *
     * Critical: SLA met &lt; 90% when a sample exists, or open tickets ≥ 25.
     * Issues: SLA met &lt; 95% when a sample exists, or open tickets ≥ 10.
     * Healthy: otherwise.
     *
     * @return array{tone: string, status_label: string, status_reason: string}
     */
    private function superOpsHealth(object $summary): array
    {
        $open = (int) ($summary->openTicketsTotal ?? 0);
        $sla = $summary->slaMetPercent;
        $slaVal = is_numeric($sla) ? (float) $sla : null;
        $slaLabel = $slaVal !== null
            ? rtrim(rtrim(number_format($slaVal, 1), '0'), '.')
            : null;

        if ($slaVal !== null && $slaVal < 90) {
            return $this->healthBand(
                'critical',
                "Only {$slaLabel}% of tickets were answered within the agreed time this month - below our critical target.",
            );
        }

        if ($open >= 25) {
            return $this->healthBand(
                'critical',
                $this->countPhrase($open, 'support ticket is still open', 'support tickets are still open').' - this is higher than we would normally expect.',
            );
        }

        if ($slaVal !== null && $slaVal < 95) {
            return $this->healthBand(
                'issues',
                "{$slaLabel}% of tickets were answered within the agreed time this month - short of the 95% target.",
            );
        }

        if ($open >= 10) {
            return $this->healthBand(
                'issues',
                $this->countPhrase($open, 'support ticket is still open', 'support tickets are still open').'.',
            );
        }

        if ($slaVal !== null) {
            return $this->healthBand(
                'healthy',
                "{$slaLabel}% of tickets met the agreed response time this month.",
            );
        }

        if ($open === 0) {
            return $this->healthBand('healthy', 'No open support tickets right now.');
        }

        return $this->healthBand(
            'healthy',
            $this->countPhrase($open, 'support ticket is open', 'support tickets are open').' - within a normal range.',
        );
    }

    /**
     * @return array{tone: string, status_label: string, status_reason: string}
     */
    private function m365Health(object $summary): array
    {
        $assigned = $summary->totalSeatsAssigned;
        $purchased = $summary->totalSeatsPurchased;

        if (is_numeric($assigned) && is_numeric($purchased) && (int) $purchased > 0) {
            $a = (int) $assigned;
            $p = (int) $purchased;
            if ($a > (int) round($p * 1.10)) {
                return $this->healthBand(
                    'critical',
                    "More Microsoft 365 licences are in use ({$a}) than you have paid for ({$p}).",
                );
            }
            if ($a > $p) {
                return $this->healthBand(
                    'issues',
                    "A few more licences are assigned ({$a}) than purchased ({$p}). We should review this with you.",
                );
            }
        }

        return $this->healthBand('healthy', 'Microsoft 365 licences look fine.');
    }

    /**
     * @return array{tone: string, status_label: string, status_reason: string}
     */
    private function huntressHealth(object $summary): array
    {
        $open = (int) ($summary->openIncidents ?? 0);
        $agents = (int) ($summary->agentsTotal ?? 0);
        $unresp = $summary->agentsUnresponsive;
        $un = is_numeric($unresp) ? (int) $unresp : 0;

        $unrespCritical = $un >= 5 && $agents > 0 && ($un / $agents) >= 0.20;

        if ($open >= 3) {
            return $this->healthBand(
                'critical',
                $this->countPhrase($open, 'security case is open', 'security cases are open').' and needing attention.',
            );
        }

        if ($unrespCritical) {
            return $this->healthBand(
                'critical',
                $this->countPhrase($un, 'computer is not protected right now', 'computers are not protected right now').' (protection software is not reporting in).',
            );
        }

        if ($open >= 1) {
            return $this->healthBand(
                'issues',
                $this->countPhrase($open, 'security case is open', 'security cases are open').' - our team is on it.',
            );
        }

        if ($un > 0) {
            return $this->healthBand(
                'issues',
                $this->countPhrase($un, 'computer is not protected right now', 'computers are not protected right now').'.',
            );
        }

        return $this->healthBand('healthy', 'No open security cases. Devices are protected.');
    }

    /**
     * @return array{tone: string, status_label: string, status_reason: string}
     */
    private function dropsuiteHealth(object $summary): array
    {
        $failed = (int) ($summary->failedLast24h ?? $summary->failedBackupsCount ?? 0);

        if ($failed > 0) {
            return $this->healthBand(
                'critical',
                $this->countPhrase($failed, 'backup did not complete cleanly', 'backups did not complete cleanly').' in the last 24 hours.',
            );
        }

        return $this->healthBand('healthy', 'Email and data backups are running normally.');
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
            href: route('client-admin.dashboard'),
            href_label: $orgWide ? 'Support & Devices →' : 'Your tickets →',
            client: $client,
            user: $user,
        );

        if ($base['state'] !== 'live_candidate') {
            return $base;
        }

        if (! is_object($summary)) {
            return $this->markState($base, 'cold', 'No SuperOps snapshot yet - waiting for auto-refresh.');
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
            $this->metric('Waiting on you', $summary->waitingOnClientTotal ?? null, null),
            $this->metric('Resolved this month', $closed30, null),
            $this->metric('Devices managed', $summary->assetsTotal, null),
            $this->metric('Healthy', $summary->assetsOnline, null),
            $this->metric('Offline', $summary->assetsOffline, null),
            $this->metric(
                'SLA met',
                $summary->slaMetPercent !== null ? $summary->slaMetPercent.'%' : null,
                $summary->slaSampleSize !== null ? 'of '.$summary->slaSampleSize.' tickets' : null,
            ),
        ];

        $health = $this->superOpsHealth($summary);

        return array_merge($base, [
            'state' => 'live',
            'tone' => $health['tone'],
            'status_label' => $health['status_label'],
            'status_reason' => $health['status_reason'],
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
                return $this->markState($base, 'setup_needed', 'Microsoft 365 tenant link is not finished - contact your account manager.');
            }

            return array_merge($base, [
                'state' => 'live',
                'tone' => 'ok',
                'status_label' => 'Healthy',
                'status_reason' => 'Open Microsoft 365 for your people and groups.',
                'message' => null,
                'metrics' => [
                    $this->metric('Directory', 'Open Microsoft 365', null),
                ],
                'as_of' => null,
            ]);
        }

        if ($base['state'] !== 'live_candidate') {
            return $base;
        }

        if (! is_object($summary)) {
            return $this->markState($base, 'cold', 'No Microsoft 365 licence snapshot yet - waiting for auto-refresh.');
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
            $this->metric(
                'Utilisation',
                $summary->overallUtilizationPct !== null ? round($summary->overallUtilizationPct).'%' : null,
                null,
            ),
            $this->metric('Licensed users', $summary->licensedUserCount ?? null, 'User mailboxes only'),
        ];

        if (isset($summary->secureScorePct) && $summary->secureScorePct !== null) {
            $metrics[] = $this->metric('Secure Score', rtrim(rtrim(number_format((float) $summary->secureScorePct, 1), '0'), '.').'%', null);
        }
        if (isset($summary->mfaRegisteredPct) && $summary->mfaRegisteredPct !== null) {
            $metrics[] = $this->metric(
                'MFA registered',
                rtrim(rtrim(number_format((float) $summary->mfaRegisteredPct, 1), '0'), '.').'%',
                isset($summary->mfaUserSample) && $summary->mfaUserSample !== null
                    ? 'of '.$summary->mfaUserSample.' members'
                    : null,
            );
        }

        $health = $this->m365Health($summary);

        return array_merge($base, [
            'state' => 'live',
            'tone' => $health['tone'],
            'status_label' => $health['status_label'],
            'status_reason' => $health['status_reason'],
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
            title: 'Security',
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
            return $this->markState($base, 'cold', 'No Huntress snapshot yet - waiting for auto-refresh.');
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

        $threatsMtd = $this->huntressThreatsStoppedMtd($client, $user);
        $threatResponses = $this->huntressThreatResponsesMtd($client, $user);

        $metrics = array_values(array_filter([
            $this->metric('Agent coverage', $summary->agentsTotal, null),
            $this->metric('24/7 monitoring', $summary->agentsTotal !== null ? 'Active' : null, null),
            $this->metric('Open incidents', $summary->openIncidents, null),
            $threatsMtd !== null
                ? $this->metric('Threats stopped (MTD)', $threatsMtd, 'closed this month')
                : null,
            $this->metric(
                'Remediated',
                $summary->resolvedIncidents,
                'lifetime handled',
            ),
            $threatResponses !== null && $threatResponses > 0
                ? $this->metric('Threat responses (MTD)', $threatResponses, 'actions logged')
                : null,
            $this->metric('Devices not reporting', $summary->agentsUnresponsive, null),
        ]));

        $health = $this->huntressHealth($summary);

        return array_merge($base, [
            'state' => 'live',
            'tone' => $health['tone'],
            'status_label' => $health['status_label'],
            'status_reason' => $health['status_reason'],
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
                : route('dashboard'),
            href_label: $orgWide ? 'Online backups →' : 'Dashboard →',
            client: $client,
            user: $user,
        );

        if ($base['state'] !== 'live_candidate') {
            return $base;
        }

        if (! is_object($summary)) {
            return $this->markState($base, 'cold', 'No Dropsuite snapshot yet - waiting for auto-refresh.');
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
        ];

        $health = $this->dropsuiteHealth($summary);

        return array_merge($base, [
            'state' => 'live',
            'tone' => $health['tone'],
            'status_label' => $health['status_label'],
            'status_reason' => $health['status_reason'],
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
            'status_label' => '-',
            'status_reason' => null,
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
            $notSold = $this->notSoldPresentation($key);

            return $this->markState(
                $column,
                'not_sold',
                $notSold['reason'],
                $notSold['label'],
            );
        }

        if ($status === ClientProductService::STATUS_SETUP_NEEDED) {
            return $this->markState(
                $column,
                'setup_needed',
                'We are still finishing the connection for this service. Your On IT account manager can help if this stays open.',
            );
        }

        if ($status === ClientProductService::STATUS_PLATFORM_DOWN) {
            return $this->markState(
                $column,
                'platform',
                'This service is temporarily unavailable on our side. On IT is fixing it.',
            );
        }

        if ($status === ClientProductService::STATUS_ERROR) {
            return $this->markState(
                $column,
                'error',
                'We could not update this service just now. On IT is looking into it.',
            );
        }

        return $column;
    }

    /**
     * Client-facing “not on plan” copy - never imply zero protection without the add-on.
     *
     * @return array{label: string, reason: string}
     */
    private function notSoldPresentation(string $productKey): array
    {
        return match ($productKey) {
            'huntress' => [
                'label' => 'Add-on',
                'reason' => '24/7 managed detection & response (Huntress) is an optional add-on - not on this plan. On IT still helps with security through support tickets and our technicians. This tile only shows automated Huntress metrics when that feed is included.',
            ],
            'dropsuite' => [
                'label' => 'Add-on',
                'reason' => 'Dedicated email & cloud backup (Dropsuite) is an optional add-on - not on this plan. On IT still helps with recovery questions on support tickets. Ask your account manager if you want this feed added.',
            ],
            'm365', 'm365_insights' => [
                'label' => 'Not on plan',
                'reason' => 'Microsoft 365 management is not on this plan. Ask your On IT account manager if you would like it added.',
            ],
            'superops' => [
                'label' => 'Not on plan',
                'reason' => 'Managed support is not on this plan. Ask your On IT account manager if you would like it added.',
            ],
            default => [
                'label' => 'Not on plan',
                'reason' => 'This service is not part of your current plan. Ask your On IT account manager if you would like it added.',
            ],
        };
    }

    /**
     * @param  array<string, mixed>  $column
     * @return array<string, mixed>
     */
    private function markState(array $column, string $state, string $message, ?string $statusLabel = null): array
    {
        $tone = match ($state) {
            'live' => 'ok',
            'loading', 'setup_needed', 'cold', 'pipeline' => 'warn',
            'not_sold' => 'muted',
            'platform', 'error' => 'bad',
            default => 'neutral',
        };

        $label = $statusLabel ?? match ($state) {
            'not_sold' => 'Not on plan',
            'setup_needed' => 'Setup needed',
            'platform' => 'Critical',
            'cold' => 'Issues',
            'loading' => 'Loading',
            'error' => 'Critical',
            'hidden' => 'Hidden',
            default => 'Issues',
        };

        $friendly = $this->clientFacingStatusMessage($state, $message, (string) ($column['title'] ?? 'This service'));

        return array_merge($column, [
            'state' => $state,
            'tone' => $tone,
            'status_label' => $label,
            'status_reason' => $friendly,
            'message' => $friendly,
            'metrics' => $column['metrics'] ?? [],
        ]);
    }

    /**
     * Cases closed (or last updated while resolved) in the current calendar month (Europe/London).
     */
    private function huntressThreatsStoppedMtd(Client $client, User $user): ?int
    {
        if (! $this->huntressIncidents->isAvailableForClient($client)) {
            return null;
        }

        $list = $this->huntressIncidents->listForClient($client, null, $user);
        if (! $list->available && $list->incidents === []) {
            return null;
        }

        $start = now()->timezone('Europe/London')->startOfMonth();
        $count = 0;
        foreach ($list->incidents as $incident) {
            if ($incident->isActive) {
                continue;
            }
            $when = $incident->closedAt ?? $incident->updatedAt ?? $incident->sentAt;
            if ($when === null) {
                continue;
            }
            if ($when->copy()->timezone('Europe/London')->gte($start)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Remediation actions on incidents touched this month (proxy ITDR narrative).
     */
    private function huntressThreatResponsesMtd(Client $client, User $user): ?int
    {
        if (! $this->huntressIncidents->isAvailableForClient($client)) {
            return null;
        }

        $list = $this->huntressIncidents->listForClient($client, null, $user);
        if (! $list->available && $list->incidents === []) {
            return null;
        }

        $start = now()->timezone('Europe/London')->startOfMonth();
        $count = 0;
        foreach ($list->incidents as $incident) {
            $when = $incident->updatedAt ?? $incident->closedAt ?? $incident->sentAt;
            if ($when === null || $when->copy()->timezone('Europe/London')->lt($start)) {
                continue;
            }
            foreach ($incident->remediations as $remediation) {
                if (is_array($remediation) && (
                    filled($remediation['action'] ?? null)
                    || filled($remediation['type'] ?? null)
                    || filled($remediation['status'] ?? null)
                )) {
                    $count++;
                }
            }
        }

        return $count;
    }

    /**
     * Prefer plain language for client admins; hide technical feed errors.
     */
    private function clientFacingStatusMessage(string $state, string $message, string $serviceTitle): string
    {
        $trimmed = trim($message);
        $lower = strtolower($trimmed);

        // Already written for clients (our product copy).
        if (
            str_contains($lower, 'on it')
            || str_contains($lower, 'your account manager')
            || str_contains($lower, 'your current plan')
            || str_contains($lower, 'loading the latest')
            || str_contains($lower, 'gathering the latest')
            || str_contains($lower, 'temporarily unavailable')
            || str_contains($lower, 'could not update')
            || str_contains($lower, 'still finishing')
        ) {
            return $trimmed;
        }

        return match ($state) {
            'loading' => 'Loading the latest figures…',
            'cold' => "We do not have the latest {$serviceTitle} figures yet. They will appear automatically when ready.",
            'setup_needed' => 'We are still finishing the connection for this service. Your On IT account manager can help if this stays open.',
            'platform', 'error' => 'We could not update this service just now. On IT is looking into it.',
            // Prefer product-specific not-on-plan copy from notSoldPresentation().
            'not_sold' => $trimmed !== ''
                ? $trimmed
                : 'This is not part of your current plan. Ask your On IT account manager if you would like it added.',
            default => $trimmed !== '' ? $trimmed : "Status for {$serviceTitle} is being checked.",
        };
    }

    /**
     * @return array{label: string, value: string, hint: ?string, kind: string, suffix: ?string}
     */
    private function metric(string $label, mixed $value, ?string $hint): array
    {
        if ($value === null || $value === '') {
            return [
                'label' => $label,
                'value' => '-',
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
     * @return array{status: string, message: string, available: bool}
     */
    private function monthComparePlaceholder(): array
    {
        return [
            'available' => false,
            'status' => 'pipeline',
            'message' => 'Your portal hasn\'t been set up for a full month yet. Last month appears once we have a previous month of readings.',
        ];
    }
}
