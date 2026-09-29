<?php

namespace App\Services\SuperOps;

use App\Jobs\RefreshSuperOpsDashboardJob;
use App\Models\Client;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Client Admin organisation metrics from SuperOps MSP GraphQL.
 *
 * @see https://developer.superops.com/msp
 */
class SuperOpsClientMetricsService
{
    /**
     * @var list<string>
     */
    public const OPEN_STATUSES = [
        'Open',
        'In Progress',
        'On Hold',
        'Pending',
        'Reopened',
        'Waiting on Client',
        'Waiting on Customer',
        'Waiting on Vendor',
    ];

    /**
     * @var list<string>
     */
    public const CLOSED_STATUSES = [
        'Closed',
        'Closed (no response)',
        'Resolved',
        'Cancelled',
    ];

    /**
     * Open tickets waiting on the customer (client admin action needed).
     *
     * @var list<string>
     */
    public const WAITING_ON_CLIENT_STATUSES = [
        'Waiting on Client',
        'Waiting on Customer',
    ];

    /**
     * Lower sort weight = higher priority in open-ticket table.
     *
     * @var array<string, int>
     */
    private const PRIORITY_WEIGHT = [
        'critical' => 0,
        'urgent' => 1,
        'high' => 2,
        'medium' => 3,
        'normal' => 4,
        'low' => 5,
    ];

    private const PAGE_SIZE = 100;

    private const OPEN_TICKET_TABLE_LIMIT = 20;

    private const CLOSED_TICKET_TABLE_LIMIT = 10;

    private const DEVICE_NAME_LIMIT = 200;

    private const RESTART_AFTER_DAYS = 2;

    public function __construct(private SuperOpsApiClient $api) {}

    public function isAvailable(): bool
    {
        return $this->api->isConfigured();
    }

    public function summaryForClient(Client $client, bool $manualRefresh = false, ?\App\Models\User $viewer = null): ClientOperationsSummary
    {
        if (! app(\App\Services\Portal\ClientProductService::class)->isEntitled($client, 'superops')) {
            return $this->unavailableSummary('SuperOps is not sold for this organisation.');
        }

        if (! $this->isAvailable()) {
            return $this->unavailableSummary('SuperOps API is not configured.');
        }

        if (empty($client->superops_account_id)) {
            return $this->unavailableSummary('SuperOps is not connected for this organisation.');
        }

        $cacheKey = $this->cacheKey($client->id);
        $cached = Cache::get($cacheKey);

        // Always serve stored metrics when present. Stale refresh is prewarm/manual only -
        // page views must not stampede the queue (requeue = PortalFreshnessService adaptive minutes).
        if (is_array($cached) && ! $manualRefresh) {
            return $this->scopeSummaryForViewer(
                $this->summaryFromCache($client->id, $cached),
                $client,
                $viewer,
            );
        }

        if ($manualRefresh) {
            $this->queueRefresh($client);
            $cached = Cache::get($cacheKey);
            if (is_array($cached)) {
                return $this->scopeSummaryForViewer(
                    $this->summaryFromCache($client->id, $cached, refreshInProgress: true),
                    $client,
                    $viewer,
                );
            }
        } else {
            $this->queueRefresh($client);
        }

        if (is_array($cached)) {
            return $this->scopeSummaryForViewer(
                $this->summaryFromCache($client->id, $cached, refreshInProgress: true),
                $client,
                $viewer,
            );
        }

        return new ClientOperationsSummary(
            assetsTotal: null,
            assetsOnline: null,
            assetsOffline: null,
            openTicketsTotal: null,
            openTicketsByPriority: [],
            openTicketsTable: [],
            slaMetPercent: null,
            slaSampleSize: null,
            ticketsCreated: $this->emptyRangeCounts(),
            ticketsClosed: $this->emptyRangeCounts(),
            lastRefreshedAt: null,
            isStale: true,
            refreshInProgress: true,
            unavailableReason: 'Data has not been synchronised yet.',
            waitingOnClientTotal: null,
        );
    }

    /**
     * Regular users only see their tickets; Client Admin / staff see org-wide SuperOps metrics.
     */
    private function scopeSummaryForViewer(
        ClientOperationsSummary $summary,
        Client $client,
        ?\App\Models\User $viewer,
    ): ClientOperationsSummary {
        if ($viewer === null) {
            return $summary;
        }

        $visibility = app(\App\Services\Portal\ClientVisibilityService::class);
        if ($visibility->canViewOrganisationWide($viewer, $client)) {
            return $summary;
        }

        $mine = array_values(array_filter(
            $summary->openTicketsTable,
            fn (array $row): bool => $this->ticketMatchesViewer($row, $viewer, $visibility),
        ));
        $mineClosed = array_values(array_filter(
            $summary->closedTicketsTable,
            fn (array $row): bool => $this->ticketMatchesViewer($row, $viewer, $visibility),
        ));

        $byPriority = [];
        foreach ($mine as $row) {
            $priority = filled($row['priority'] ?? null) ? $row['priority'] : 'Unspecified';
            $byPriority[$priority] = ($byPriority[$priority] ?? 0) + 1;
        }

        $waiting = 0;
        foreach ($mine as $row) {
            if ($this->isWaitingOnClientStatus((string) ($row['status'] ?? ''))) {
                $waiting++;
            }
        }

        $categories = [];
        foreach (array_merge($mine, $mineClosed) as $row) {
            $category = filled($row['category'] ?? null) ? (string) $row['category'] : 'Unspecified';
            $categories[$category] = ($categories[$category] ?? 0) + 1;
        }
        arsort($categories);

        return new ClientOperationsSummary(
            assetsTotal: null,
            assetsOnline: null,
            assetsOffline: null,
            openTicketsTotal: count($mine),
            openTicketsByPriority: $byPriority,
            openTicketsTable: $mine,
            slaMetPercent: null,
            slaSampleSize: null,
            ticketsCreated: $this->emptyRangeCounts(),
            ticketsClosed: $this->emptyRangeCounts(),
            lastRefreshedAt: $summary->lastRefreshedAt,
            isStale: $summary->isStale,
            refreshInProgress: $summary->refreshInProgress,
            unavailableReason: $summary->unavailableReason,
            waitingOnClientTotal: $waiting,
            closedTicketsTable: $mineClosed,
            ticketsByCategory: $categories,
            deviceInsights: [],
        );
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function ticketMatchesViewer(array $row, \App\Models\User $viewer, \App\Services\Portal\ClientVisibilityService $visibility): bool
    {
        if ($visibility->matchesEmail($row['requesterEmail'] ?? null, $viewer)) {
            return true;
        }
        if (filled($viewer->superops_user_id)
            && (string) ($row['requesterUserId'] ?? '') === (string) $viewer->superops_user_id) {
            return true;
        }

        return $visibility->matchesPerson($viewer, [
            $row['requesterEmail'] ?? null,
            $row['requesterName'] ?? null,
            $row['subject'] ?? null,
        ]);
    }

    /**
     * True when SuperOps is linked and a dashboard payload is already in cache.
     */
    public function hasStoredSummary(Client $client): bool
    {
        if (! app(\App\Services\Portal\ClientProductService::class)->shouldRefresh($client, 'superops')) {
            return false;
        }

        return is_array(Cache::get($this->cacheKey($client->id)));
    }

    /**
     * True when SuperOps is linked but there is no cache yet (must prewarm).
     */
    public function needsColdPrewarm(Client $client): bool
    {
        if (! app(\App\Services\Portal\ClientProductService::class)->shouldRefresh($client, 'superops')) {
            return false;
        }

        return ! $this->hasStoredSummary($client);
    }

    /**
     * True when cache exists but is due for a background SuperOps pull and no real job is queued/running.
     * Clears orphaned `refresh_queued` cache when the jobs table has no matching row.
     *
     * Requeue age comes from PortalFreshnessService (hot when customers are online; idle otherwise).
     */
    public function needsBackgroundRefresh(Client $client): bool
    {
        if (! $this->hasStoredSummary($client)) {
            return false;
        }

        $this->clearOrphanedRefreshFlags($client->id);

        if (Cache::has('superops_dashboard.refresh_queued.'.$client->id)) {
            return false;
        }

        $payload = Cache::get($this->cacheKey($client->id));
        if (! is_array($payload) || ! filled($payload['last_refreshed_at'] ?? null)) {
            return true;
        }

        $after = max(0.5, app(\App\Services\Portal\PortalFreshnessService::class)->effectiveRequeueMinutes());
        // ~20s early so aligned ticks do not skip a client by a hair
        $seconds = max(30, (int) round($after * 60) - 20);

        return Carbon::parse($payload['last_refreshed_at'])->lte(now()->subSeconds($seconds));
    }

    public function queueRefresh(Client $client, bool $respectCooldown = false): bool
    {
        if (! app(\App\Services\Portal\ClientProductService::class)->shouldRefresh($client, 'superops')) {
            return false;
        }

        $this->clearOrphanedRefreshFlags($client->id);

        if ($respectCooldown) {
            $cooldownKey = 'superops_dashboard.refresh_cooldown.'.$client->id;
            if (Cache::has($cooldownKey)) {
                return false;
            }
            Cache::put($cooldownKey, true, now()->addSeconds(
                (int) config('services.superops.dashboard_refresh_cooldown_seconds', 60),
            ));
        }

        if (Cache::has('superops_dashboard.refresh_queued.'.$client->id)) {
            return false;
        }

        Cache::put('superops_dashboard.refresh_queued.'.$client->id, true, now()->addMinutes(15));
        RefreshSuperOpsDashboardJob::dispatch($client->id);

        return true;
    }

    public function refreshAndStore(Client $client): ClientOperationsSummary
    {
        $accountId = (string) $client->superops_account_id;
        $lock = Cache::lock('superops_dashboard.refresh.'.$client->id, 300);

        if (! $lock->get()) {
            $cached = Cache::get($this->cacheKey($client->id));
            if (is_array($cached)) {
                return $this->summaryFromCache($client->id, $cached, refreshInProgress: true);
            }

            throw new \RuntimeException('Dashboard refresh already in progress.');
        }

        try {
            $tickets = $this->listClientTickets($accountId);
            $assetHealth = $this->summariseClientAssetHealth($accountId);

            $payload = [
                'assets_total' => $assetHealth['total'],
                'assets_online' => $assetHealth['online'],
                'assets_offline' => $assetHealth['offline'],
                'device_insights' => $assetHealth['insights'],
                'open_tickets_total' => $this->countOpenTickets($tickets),
                'waiting_on_client_total' => $this->countWaitingOnClient($tickets),
                'open_tickets_by_priority' => $this->openTicketsByPriority($tickets),
                'open_tickets_table' => $this->openTicketsTable($tickets),
                'closed_tickets_table' => $this->closedTicketsTable($tickets),
                'tickets_by_category' => $this->ticketsByCategory($tickets),
                'sla_met_percent' => $this->slaMetPercent($tickets),
                'sla_sample_size' => $this->slaSampleSize($tickets),
                'tickets_created' => $this->countTicketsCreated($tickets),
                'tickets_closed' => $this->countTicketsClosed($tickets),
                'last_refreshed_at' => now()->toIso8601String(),
            ];

            Cache::put(
                $this->cacheKey($client->id),
                $payload,
                now()->addMinutes((int) config('services.superops.dashboard_stale_minutes', 1440)),
            );

            return $this->summaryFromCache($client->id, $payload);
        } catch (Throwable $e) {
            Log::error('SuperOps dashboard refresh failed', [
                'client_id' => $client->id,
                'account_id' => $accountId,
                'error' => $e->getMessage(),
            ]);

            // Rethrow even when a previous snapshot is cached: the calling job
            // records last_result.success=false from the exception, which is what
            // drives the client-facing error state and Integration Health. The
            // cached snapshot is left untouched and still served to page views.
            throw $e;
        } finally {
            Cache::forget('superops_dashboard.refresh_queued.'.$client->id);
            $lock->release();
        }
    }

    /**
     * @return array{total: ?int, online: ?int, offline: ?int, insights: array<string, mixed>}
     */
    private function summariseClientAssetHealth(string $accountId): array
    {
        $emptyInsights = $this->emptyDeviceInsights();

        try {
            $online = 0;
            $offline = 0;
            $total = null;
            $page = 1;
            $maxPages = (int) config('services.superops.dashboard_max_pages', 10);
            $assets = [];

            do {
                $pageData = $this->api->query(<<<'GQL'
                    query getAssetList($input: ListInfoInput!) {
                        getAssetList(input: $input) {
                            assets {
                                assetId
                                name
                                status
                                platform
                                lastCommunicatedTime
                                lastReportedTime
                                sysUptime
                                patchStatus
                                purchasedDate
                            }
                            listInfo { totalCount hasMore }
                        }
                    }
                GQL, [
                    'input' => [
                        'page' => $page,
                        'pageSize' => self::PAGE_SIZE,
                        'condition' => $this->clientAccountCondition($accountId),
                    ],
                ]);

                if ($page === 1 && isset($pageData['getAssetList']['listInfo']['totalCount'])) {
                    $total = (int) $pageData['getAssetList']['listInfo']['totalCount'];
                    if ($total === 0) {
                        return ['total' => 0, 'online' => 0, 'offline' => 0, 'insights' => $emptyInsights];
                    }
                }

                $batch = $pageData['getAssetList']['assets'] ?? [];
                $hasMore = (bool) ($pageData['getAssetList']['listInfo']['hasMore'] ?? false);

                foreach ($batch as $asset) {
                    $assets[] = is_array($asset) ? $asset : [];
                    if (strtoupper((string) ($asset['status'] ?? '')) === 'ONLINE') {
                        $online++;
                    } else {
                        $offline++;
                    }
                }

                $page++;
            } while ($hasMore && $batch !== [] && $page <= $maxPages);

            if ($total === null) {
                $total = $online + $offline;
            }

            return [
                'total' => $total,
                'online' => $online,
                'offline' => $offline,
                'insights' => $this->deviceInsightsFromAssets($assets),
            ];
        } catch (Throwable $e) {
            Log::warning('SuperOps dashboard asset health unavailable', [
                'account_id' => $accountId,
                'error' => $e->getMessage(),
            ]);

            return ['total' => null, 'online' => null, 'offline' => null, 'insights' => $emptyInsights];
        }
    }

    /**
     * @return list<array{
     *     status: string,
     *     createdTime: ?string,
     *     updatedTime: ?string,
     *     resolutionTime: ?string,
     *     displayId: string,
     *     subject: string,
     *     priority: string,
     *     resolutionViolated: ?bool,
     * }>
     */
    private function listClientTickets(string $accountId): array
    {
        $tickets = [];
        $page = 1;

        do {
            $data = $this->api->query(<<<'GQL'
                query getTicketList($input: ListInfoInput!) {
                    getTicketList(input: $input) {
                        tickets {
                            ticketId
                            displayId
                            subject
                            priority
                            status
                            createdTime
                            updatedTime
                            resolutionTime
                            resolutionViolated
                            requester
                            category
                        }
                        listInfo { totalCount hasMore }
                    }
                }
            GQL, [
                'input' => [
                    'page' => $page,
                    'pageSize' => self::PAGE_SIZE,
                    'condition' => $this->clientAccountCondition($accountId),
                    'sort' => [
                        ['attribute' => 'createdTime', 'order' => 'DESC'],
                    ],
                ],
            ]);

            $batch = $data['getTicketList']['tickets'] ?? [];
            $hasMore = (bool) ($data['getTicketList']['listInfo']['hasMore'] ?? false);
            $total = (int) ($data['getTicketList']['listInfo']['totalCount'] ?? 0);

            if ($batch === [] && $total > 0 && $page === 1) {
                throw new \RuntimeException(
                    'SuperOps returned ticket totalCount without ticket rows. Ensure ticketId is selected.'
                );
            }

            foreach ($batch as $ticket) {
                $requester = $this->normalizeJsonObject($ticket['requester'] ?? null);
                $tickets[] = [
                    'status' => $this->statusName($ticket['status'] ?? null),
                    'createdTime' => $ticket['createdTime'] ?? null,
                    'updatedTime' => $ticket['updatedTime'] ?? null,
                    'resolutionTime' => $ticket['resolutionTime'] ?? null,
                    'displayId' => (string) ($ticket['displayId'] ?? ''),
                    'subject' => (string) ($ticket['subject'] ?? ''),
                    'priority' => $this->priorityName($ticket['priority'] ?? null),
                    'category' => $this->categoryName($ticket['category'] ?? null),
                    'resolutionViolated' => isset($ticket['resolutionViolated'])
                        ? (bool) $ticket['resolutionViolated']
                        : null,
                    'requesterEmail' => strtolower((string) ($requester['email'] ?? '')),
                    'requesterName' => (string) ($requester['name'] ?? ''),
                    'requesterUserId' => (string) ($requester['userId'] ?? $requester['user_id'] ?? ''),
                ];
            }

            $page++;
        } while ($hasMore && $batch !== [] && $page <= (int) config('services.superops.dashboard_max_pages', 10));

        return $tickets;
    }

    /**
     * @return array{attribute: string, operator: string, value: string}
     */
    private function clientAccountCondition(string $accountId): array
    {
        return [
            'attribute' => 'client.accountId',
            'operator' => 'is',
            'value' => $accountId,
        ];
    }

    /**
     * @param  list<array{status: string, priority: string}>  $tickets
     */
    private function countOpenTickets(array $tickets): int
    {
        $count = 0;

        foreach ($tickets as $ticket) {
            if ($this->classifyStatus($ticket['status']) === 'open') {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param  list<array{status: string}>  $tickets
     */
    private function countWaitingOnClient(array $tickets): int
    {
        $count = 0;

        foreach ($tickets as $ticket) {
            if ($this->isWaitingOnClientStatus($ticket['status'] ?? '')) {
                $count++;
            }
        }

        return $count;
    }

    private function isWaitingOnClientStatus(string $status): bool
    {
        return in_array($status, self::WAITING_ON_CLIENT_STATUSES, true);
    }

    /**
     * @param  list<array{status: string, priority: string}>  $tickets
     * @return array<string, int>
     */
    private function openTicketsByPriority(array $tickets): array
    {
        $counts = [];

        foreach ($tickets as $ticket) {
            if ($this->classifyStatus($ticket['status']) !== 'open') {
                continue;
            }

            $priority = $ticket['priority'] !== '' ? $ticket['priority'] : 'Unspecified';
            $counts[$priority] = ($counts[$priority] ?? 0) + 1;
        }

        arsort($counts);

        return $counts;
    }

    /**
     * @param  list<array{
     *     status: string,
     *     displayId: string,
     *     subject: string,
     *     priority: string,
     *     createdTime: ?string,
     *     updatedTime: ?string,
     * }>  $tickets
     * @return list<array{displayId: string, subject: string, priority: string, status: string, createdTime: ?string}>
     */
    private function openTicketsTable(array $tickets): array
    {
        $open = array_values(array_filter(
            $tickets,
            fn (array $ticket): bool => $this->classifyStatus($ticket['status']) === 'open',
        ));

        usort($open, function (array $a, array $b): int {
            $weightA = self::PRIORITY_WEIGHT[strtolower($a['priority'])] ?? 99;
            $weightB = self::PRIORITY_WEIGHT[strtolower($b['priority'])] ?? 99;

            if ($weightA !== $weightB) {
                return $weightA <=> $weightB;
            }

            $createdA = $this->parseTimestamp($a['createdTime'])?->timestamp ?? 0;
            $createdB = $this->parseTimestamp($b['createdTime'])?->timestamp ?? 0;

            return $createdB <=> $createdA;
        });

        return array_map(
            fn (array $ticket): array => $this->ticketTableRow($ticket),
            array_slice($open, 0, self::OPEN_TICKET_TABLE_LIMIT),
        );
    }

    /**
     * @param  list<array<string, mixed>>  $tickets
     * @return list<array<string, mixed>>
     */
    private function closedTicketsTable(array $tickets): array
    {
        $closed = array_values(array_filter(
            $tickets,
            fn (array $ticket): bool => $this->classifyStatus($ticket['status']) === 'closed',
        ));

        usort($closed, function (array $a, array $b): int {
            $resolvedA = $this->parseTimestamp($a['resolutionTime'] ?? null)?->timestamp
                ?? $this->parseTimestamp($a['createdTime'] ?? null)?->timestamp
                ?? 0;
            $resolvedB = $this->parseTimestamp($b['resolutionTime'] ?? null)?->timestamp
                ?? $this->parseTimestamp($b['createdTime'] ?? null)?->timestamp
                ?? 0;

            return $resolvedB <=> $resolvedA;
        });

        return array_map(
            fn (array $ticket): array => $this->ticketTableRow($ticket),
            array_slice($closed, 0, self::CLOSED_TICKET_TABLE_LIMIT),
        );
    }

    /**
     * @param  array<string, mixed>  $ticket
     * @return array<string, mixed>
     */
    private function ticketTableRow(array $ticket): array
    {
        return [
            'displayId' => $ticket['displayId'],
            'subject' => $ticket['subject'],
            'priority' => $ticket['priority'],
            'status' => $ticket['status'],
            'createdTime' => $ticket['createdTime'],
            'updatedTime' => $ticket['updatedTime'] ?? null,
            'resolutionTime' => $ticket['resolutionTime'] ?? null,
            'category' => (string) ($ticket['category'] ?? ''),
            'requesterEmail' => (string) ($ticket['requesterEmail'] ?? ''),
            'requesterName' => (string) ($ticket['requesterName'] ?? ''),
            'requesterUserId' => (string) ($ticket['requesterUserId'] ?? ''),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $tickets
     * @return array<string, int>
     */
    private function ticketsByCategory(array $tickets): array
    {
        $counts = [];

        foreach ($tickets as $ticket) {
            $category = filled($ticket['category'] ?? null) ? (string) $ticket['category'] : 'Unspecified';
            $counts[$category] = ($counts[$category] ?? 0) + 1;
        }

        arsort($counts);

        return $counts;
    }

    /**
     * Resolution SLA met % for tickets closed in the last 30 days.
     *
     * @param  list<array{status: string, resolutionTime: ?string, resolutionViolated: ?bool}>  $tickets
     */
    private function slaMetPercent(array $tickets): ?int
    {
        $sample = $this->slaSampleSize($tickets);

        if ($sample === null || $sample === 0) {
            return null;
        }

        $met = 0;
        $since = now()->subDays(30)->startOfDay();

        foreach ($tickets as $ticket) {
            if ($this->classifyStatus($ticket['status']) !== 'closed') {
                continue;
            }

            $resolved = $this->parseTimestamp($ticket['resolutionTime']);
            if ($resolved === null || $resolved->lt($since)) {
                continue;
            }

            if ($ticket['resolutionViolated'] !== true) {
                $met++;
            }
        }

        return (int) round(($met / $sample) * 100);
    }

    /**
     * @param  list<array{status: string, resolutionTime: ?string, resolutionViolated: ?bool}>  $tickets
     */
    private function slaSampleSize(array $tickets): ?int
    {
        $since = now()->subDays(30)->startOfDay();
        $count = 0;

        foreach ($tickets as $ticket) {
            if ($this->classifyStatus($ticket['status']) !== 'closed') {
                continue;
            }

            $resolved = $this->parseTimestamp($ticket['resolutionTime']);
            if ($resolved === null || $resolved->lt($since)) {
                continue;
            }

            if ($ticket['resolutionViolated'] !== null) {
                $count++;
            }
        }

        return $count > 0 ? $count : null;
    }

    /**
     * @param  list<array{status: string, createdTime: ?string, resolutionTime: ?string}>  $tickets
     * @return array<string, int>
     */
    private function countTicketsCreated(array $tickets): array
    {
        return [
            '7' => $this->countCreatedSince($tickets, 7),
            '14' => $this->countCreatedSince($tickets, 14),
            '30' => $this->countCreatedSince($tickets, 30),
            'all' => count($tickets),
        ];
    }

    /**
     * @param  list<array{status: string, createdTime: ?string, resolutionTime: ?string}>  $tickets
     * @return array<string, int>
     */
    private function countTicketsClosed(array $tickets): array
    {
        return [
            '7' => $this->countClosedSince($tickets, 7),
            '14' => $this->countClosedSince($tickets, 14),
            '30' => $this->countClosedSince($tickets, 30),
            'all' => $this->countClosedSince($tickets, null),
        ];
    }

    /**
     * @param  list<array{status: string, createdTime: ?string}>  $tickets
     */
    private function countCreatedSince(array $tickets, int $days): int
    {
        $since = now()->subDays($days)->startOfDay();
        $count = 0;

        foreach ($tickets as $ticket) {
            $created = $this->parseTimestamp($ticket['createdTime']);
            if ($created && $created->gte($since)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param  list<array{status: string, resolutionTime: ?string}>  $tickets
     */
    private function countClosedSince(array $tickets, ?int $days): int
    {
        $since = $days === null ? null : now()->subDays($days)->startOfDay();
        $count = 0;

        foreach ($tickets as $ticket) {
            if ($this->classifyStatus($ticket['status']) !== 'closed') {
                continue;
            }

            $resolved = $this->parseTimestamp($ticket['resolutionTime']);
            if ($resolved === null) {
                continue;
            }

            if ($since === null || $resolved->gte($since)) {
                $count++;
            }
        }

        return $count;
    }

    private function classifyStatus(string $status): ?string
    {
        if ($status === '') {
            return null;
        }

        if (in_array($status, self::OPEN_STATUSES, true)) {
            return 'open';
        }

        if (in_array($status, self::CLOSED_STATUSES, true)) {
            return 'closed';
        }

        Log::warning('SuperOps dashboard encountered unknown ticket status', [
            'status' => $status,
        ]);

        return null;
    }

    private function statusName(mixed $status): string
    {
        if (is_array($status)) {
            return (string) ($status['name'] ?? '');
        }

        return (string) ($status ?? '');
    }

    private function priorityName(mixed $priority): string
    {
        if (is_array($priority)) {
            return (string) ($priority['name'] ?? $priority['id'] ?? '');
        }

        return (string) ($priority ?? '');
    }

    private function categoryName(mixed $category): string
    {
        if (is_array($category)) {
            return (string) ($category['name'] ?? $category['id'] ?? '');
        }

        $normalized = $this->normalizeJsonObject($category);
        if ($normalized !== []) {
            return (string) ($normalized['name'] ?? $normalized['id'] ?? '');
        }

        return is_string($category) ? $category : '';
    }

    private function parseTimestamp(?string $value): ?Carbon
    {
        if (! filled($value)) {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function summaryFromCache(
        int $clientId,
        array $payload,
        bool $isStale = false,
        bool $refreshInProgress = false,
    ): ClientOperationsSummary {
        $lastRefreshedAt = filled($payload['last_refreshed_at'] ?? null)
            ? Carbon::parse($payload['last_refreshed_at'])
            : null;

        if ($lastRefreshedAt && ! $isStale) {
            $isStale = $lastRefreshedAt->lte(now()->subMinutes(
                (int) config('services.superops.dashboard_cache_minutes', 60),
            ));
        }

        $waiting = $payload['waiting_on_client_total'] ?? null;
        if ($waiting === null && is_array($payload['open_tickets_table'] ?? null)) {
            $waiting = 0;
            foreach ($payload['open_tickets_table'] as $row) {
                if (is_array($row) && $this->isWaitingOnClientStatus((string) ($row['status'] ?? ''))) {
                    $waiting++;
                }
            }
        }

        return new ClientOperationsSummary(
            assetsTotal: $payload['assets_total'] ?? null,
            assetsOnline: $payload['assets_online'] ?? null,
            assetsOffline: $payload['assets_offline'] ?? null,
            openTicketsTotal: $payload['open_tickets_total'] ?? null,
            openTicketsByPriority: $payload['open_tickets_by_priority'] ?? [],
            openTicketsTable: $payload['open_tickets_table'] ?? [],
            slaMetPercent: $payload['sla_met_percent'] ?? null,
            slaSampleSize: $payload['sla_sample_size'] ?? null,
            ticketsCreated: $payload['tickets_created'] ?? $this->emptyRangeCounts(),
            ticketsClosed: $payload['tickets_closed'] ?? $this->emptyRangeCounts(),
            lastRefreshedAt: $lastRefreshedAt,
            isStale: $isStale,
            refreshInProgress: $refreshInProgress || Cache::has('superops_dashboard.refresh_queued.'.$clientId),
            waitingOnClientTotal: is_numeric($waiting) ? (int) $waiting : null,
            closedTicketsTable: $payload['closed_tickets_table'] ?? [],
            ticketsByCategory: $payload['tickets_by_category'] ?? [],
            deviceInsights: is_array($payload['device_insights'] ?? null)
                ? $payload['device_insights']
                : $this->emptyDeviceInsights(),
        );
    }

    private function unavailableSummary(string $reason): ClientOperationsSummary
    {
        return new ClientOperationsSummary(
            assetsTotal: null,
            assetsOnline: null,
            assetsOffline: null,
            openTicketsTotal: null,
            openTicketsByPriority: [],
            openTicketsTable: [],
            slaMetPercent: null,
            slaSampleSize: null,
            ticketsCreated: $this->emptyRangeCounts(),
            ticketsClosed: $this->emptyRangeCounts(),
            lastRefreshedAt: null,
            isStale: false,
            refreshInProgress: false,
            unavailableReason: $reason,
            waitingOnClientTotal: null,
        );
    }

    /**
     * @return array<string, null>
     */
    private function emptyRangeCounts(): array
    {
        return ['7' => null, '14' => null, '30' => null, 'all' => null];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyDeviceInsights(): array
    {
        return [
            'offline_30d' => ['count' => 0, 'names' => []],
            'needs_restart' => ['count' => 0, 'names' => []],
            'patch' => ['fully' => 0, 'not_fully' => 0, 'unknown' => 0],
            'edition' => ['home' => 0, 'pro' => 0, 'server' => 0, 'other' => 0],
            'age' => ['under_3' => 0, '3_to_5' => 0, 'over_5' => 0, 'unknown' => 0],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $assets
     * @return array<string, mixed>
     */
    private function deviceInsightsFromAssets(array $assets): array
    {
        $insights = $this->emptyDeviceInsights();
        $offlineNames = [];
        $restartNames = [];
        $cutoff = now()->subDays(30);

        foreach ($assets as $asset) {
            $name = (string) ($asset['name'] ?? $asset['hostName'] ?? $asset['assetId'] ?? 'Device');
            $lastSeen = $this->parseTimestamp($asset['lastCommunicatedTime'] ?? null)
                ?? $this->parseTimestamp($asset['lastReportedTime'] ?? null);
            $isOffline = strtoupper((string) ($asset['status'] ?? '')) !== 'ONLINE';

            if ($isOffline && $lastSeen !== null && $lastSeen->lte($cutoff)) {
                $insights['offline_30d']['count']++;
                $offlineNames[] = $name;
            }

            $uptimeDays = $this->uptimeDays($asset['sysUptime'] ?? null);
            if ($uptimeDays !== null && $uptimeDays >= self::RESTART_AFTER_DAYS) {
                $insights['needs_restart']['count']++;
                $restartNames[] = $name;
            }

            $patch = strtolower(trim((string) ($asset['patchStatus'] ?? '')));
            if ($patch === '') {
                $insights['patch']['unknown']++;
            } elseif (str_contains($patch, 'fully') || $patch === 'patched' || str_contains($patch, 'up to date')) {
                $insights['patch']['fully']++;
            } else {
                $insights['patch']['not_fully']++;
            }

            $platform = strtolower((string) ($asset['platform'] ?? ''));
            if (str_contains($platform, 'server')) {
                $insights['edition']['server']++;
            } elseif (preg_match('/\bhome\b/', $platform)) {
                $insights['edition']['home']++;
            } elseif (preg_match('/\bpro\b/', $platform) || str_contains($platform, 'professional')) {
                $insights['edition']['pro']++;
            } else {
                $insights['edition']['other']++;
            }

            $purchased = $this->parseTimestamp($asset['purchasedDate'] ?? null);
            if ($purchased === null || $purchased->isFuture()) {
                $insights['age']['unknown']++;
            } else {
                $years = $purchased->diffInYears(now());
                if ($years < 3) {
                    $insights['age']['under_3']++;
                } elseif ($years < 5) {
                    $insights['age']['3_to_5']++;
                } else {
                    $insights['age']['over_5']++;
                }
            }
        }

        $insights['offline_30d']['names'] = array_values(array_slice($offlineNames, 0, self::DEVICE_NAME_LIMIT));
        $insights['needs_restart']['names'] = array_values(array_slice($restartNames, 0, self::DEVICE_NAME_LIMIT));

        return $insights;
    }

    private function uptimeDays(?string $uptime): ?int
    {
        if (! filled($uptime)) {
            return null;
        }

        if (preg_match('/(\d+)\s*day/i', $uptime, $matches)) {
            return (int) $matches[1];
        }

        return 0;
    }

    public function cacheKey(int $clientId): string
    {
        return "client:{$clientId}:superops-dashboard:v5";
    }

    /**
     * Drop phantom "in progress" when flag is set but no matching job row exists.
     * (Workers died, unique discard, or deploy mid-flight - blocked prewarm for 40+ minutes.)
     */
    private function clearOrphanedRefreshFlags(int $clientId): void
    {
        if (! Cache::has('superops_dashboard.refresh_queued.'.$clientId)) {
            return;
        }

        if ($this->jobPendingInDatabase($clientId)) {
            return;
        }

        Cache::forget('superops_dashboard.refresh_queued.'.$clientId);
        Cache::forget('superops_dashboard.refresh_started.'.$clientId);
    }

    private function jobPendingInDatabase(int $clientId): bool
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('jobs')) {
            return false;
        }

        return \Illuminate\Support\Facades\DB::table('jobs')
            ->where('payload', 'like', '%RefreshSuperOpsDashboardJob%')
            ->where('payload', 'like', '%clientId";i:'.$clientId.';%')
            ->exists();
    }

    /**
     * SuperOps often returns person blobs as JSON leaves (not GraphQL objects).
     *
     * @return array<string, mixed>
     */
    private function normalizeJsonObject(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }
}
