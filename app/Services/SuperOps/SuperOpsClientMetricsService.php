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
        'Waiting on third party',
        'Still Issue',
        'Awaiting Approval',
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
        'Still Issue',
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

    public function __construct(
        private SuperOpsApiClient $api,
        private DeviceEstateAssessor $devices,
    ) {}

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
                is_array($cached['requester_tickets'] ?? null) ? $cached['requester_tickets'] : null,
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
                    is_array($cached['requester_tickets'] ?? null) ? $cached['requester_tickets'] : null,
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
                is_array($cached['requester_tickets'] ?? null) ? $cached['requester_tickets'] : null,
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
        ?array $requesterRows = null,
    ): ClientOperationsSummary {
        if ($viewer === null) {
            return $summary;
        }

        $visibility = app(\App\Services\Portal\ClientVisibilityService::class);
        if ($visibility->canViewOrganisationWide($viewer, $client)) {
            return $summary;
        }

        // Prefer the untruncated per-requester rows; the org tables are capped
        // (top OPEN_TICKET_TABLE_LIMIT by priority, CLOSED_TICKET_TABLE_LIMIT most
        // recent), so filtering them would silently drop a requester's own
        // lower-priority / older tickets. Legacy payloads without the key fall
        // back to the org tables until the next refresh.
        if (is_array($requesterRows)) {
            $openSource = array_values(array_filter(
                $requesterRows,
                fn ($row): bool => is_array($row) && $this->classifyStatus((string) ($row['status'] ?? '')) === 'open',
            ));
            $closedSource = array_values(array_filter(
                $requesterRows,
                fn ($row): bool => is_array($row) && $this->classifyStatus((string) ($row['status'] ?? '')) === 'closed',
            ));
        } else {
            $openSource = $summary->openTicketsTable;
            $closedSource = $summary->closedTicketsTable;
        }

        $mine = array_values(array_filter(
            $openSource,
            fn (array $row): bool => $this->ticketMatchesViewer($row, $viewer, $visibility),
        ));
        $mineClosed = array_slice(array_values(array_filter(
            $closedSource,
            fn (array $row): bool => $this->ticketMatchesViewer($row, $viewer, $visibility),
        )), 0, self::CLOSED_TICKET_TABLE_LIMIT);

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
            openTicketsTable: array_slice($mine, 0, self::OPEN_TICKET_TABLE_LIMIT),
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
        // Requester identity only (exact email or bound SuperOps user id). Never
        // substring-match the subject / requester name: "mark@" would match
        // "marketing@", and a ticket *about* a person (a leaver request) would
        // be shown to that person.
        if ($visibility->matchesEmail($row['requesterEmail'] ?? null, $viewer)) {
            return true;
        }

        return filled($viewer->superops_user_id)
            && (string) ($row['requesterUserId'] ?? '') === (string) $viewer->superops_user_id;
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

        if ($this->inRefreshBackoff($client)) {
            return false;
        }

        return ! $this->hasStoredSummary($client);
    }

    public function refreshBackoffKey(int $clientId): string
    {
        return 'superops_dashboard.refresh_backoff.'.$clientId;
    }

    public function inRefreshBackoff(Client $client): bool
    {
        return Cache::has($this->refreshBackoffKey($client->id));
    }

    /**
     * After a failed pull, wait out the current requeue interval (at least 15 minutes)
     * before the minute checker queues the same client again.
     */
    public function markRefreshBackoff(Client $client): void
    {
        $minutes = max(15, (int) ceil(
            app(\App\Services\Portal\PortalFreshnessService::class)->effectiveRequeueMinutes()
        ));

        Cache::put($this->refreshBackoffKey($client->id), now()->toIso8601String(), now()->addMinutes($minutes));
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

        if ($this->inRefreshBackoff($client)) {
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
            $previous = Cache::get($this->cacheKey($client->id));
            $previousHardware = is_array($previous['device_hardware'] ?? null) ? $previous['device_hardware'] : [];
            $assetHealth = $this->summariseClientAssetHealth($accountId, $previousHardware);

            $payload = [
                'assets_total' => $assetHealth['total'],
                'assets_online' => $assetHealth['online'],
                'assets_offline' => $assetHealth['offline'],
                'device_insights' => $assetHealth['insights'],
                'device_hardware' => $assetHealth['hardware'],
                'open_tickets_total' => $this->countOpenTickets($tickets),
                'waiting_on_client_total' => $this->countWaitingOnClient($tickets),
                'open_tickets_by_priority' => $this->openTicketsByPriority($tickets),
                'open_tickets_table' => $this->openTicketsTable($tickets),
                'closed_tickets_table' => $this->closedTicketsTable($tickets),
                'requester_tickets' => $this->requesterTicketRows($tickets),
                'tickets_by_category' => $this->ticketsByCategory($tickets),
                'sla_met_percent' => $this->slaMetPercent($tickets, 'mtd'),
                'sla_sample_size' => $this->slaSampleSize($tickets, 'mtd'),
                'sla_met_percent_prev_month' => $this->slaMetPercent($tickets, 'prev_month'),
                'sla_sample_size_prev_month' => $this->slaSampleSize($tickets, 'prev_month'),
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
     * @param  array<string, mixed>  $previousHardware
     * @return array{total: ?int, online: ?int, offline: ?int, insights: array<string, mixed>, hardware: array<string, mixed>}
     */
    private function summariseClientAssetHealth(string $accountId, array $previousHardware = []): array
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
                                warrantyExpiryDate
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
                        return ['total' => 0, 'online' => 0, 'offline' => 0, 'insights' => $emptyInsights, 'hardware' => []];
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

            $hardware = $this->collectHardwareSamples($assets, $previousHardware);

            return [
                'total' => $total,
                'online' => $online,
                'offline' => $offline,
                'insights' => $this->deviceInsightsFromAssets($assets, $hardware),
                'hardware' => $hardware,
            ];
        } catch (Throwable $e) {
            Log::warning('SuperOps dashboard asset health unavailable', [
                'account_id' => $accountId,
                'error' => $e->getMessage(),
            ]);

            return ['total' => null, 'online' => null, 'offline' => null, 'insights' => $emptyInsights, 'hardware' => $previousHardware];
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
                    'ticketId' => (string) ($ticket['ticketId'] ?? ''),
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
        if (in_array($status, self::WAITING_ON_CLIENT_STATUSES, true)) {
            return true;
        }

        // The chase after Still Issue keeps "Still Issue" in the name.
        return str_contains(strtolower($status), 'still issue');
    }

    private function isThirdPartyStatus(string $status): bool
    {
        $key = strtolower($status);

        return str_contains($key, 'third party') || str_contains($key, '3rd party');
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
    private function openTicketsTable(array $tickets, ?int $limit = self::OPEN_TICKET_TABLE_LIMIT): array
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
            $limit === null ? $open : array_slice($open, 0, $limit),
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
     * Untruncated rows for personal (requester) scoping: every open ticket, plus
     * each requester's CLOSED_TICKET_TABLE_LIMIT most recently closed tickets.
     * Rows with no requester identity can never match a viewer and are skipped.
     *
     * @param  list<array<string, mixed>>  $tickets
     * @return list<array<string, mixed>>
     */
    private function requesterTicketRows(array $tickets): array
    {
        $open = [];
        $closedByRequester = [];

        foreach ($tickets as $ticket) {
            $email = strtolower(trim((string) ($ticket['requesterEmail'] ?? '')));
            $userId = trim((string) ($ticket['requesterUserId'] ?? ''));
            if ($email === '' && $userId === '') {
                continue;
            }

            $state = $this->classifyStatus((string) ($ticket['status'] ?? ''));
            if ($state === 'open') {
                $open[] = $ticket;
            } elseif ($state === 'closed') {
                $closedByRequester[$userId !== '' ? 'id:'.$userId : 'email:'.$email][] = $ticket;
            }
        }

        $rows = $this->openTicketsTable($open, null);
        foreach ($closedByRequester as $requesterTickets) {
            array_push($rows, ...$this->closedTicketsTable($requesterTickets));
        }

        return $rows;
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
            'ticketId' => (string) ($ticket['ticketId'] ?? ''),
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
     * Resolution SLA met % for closed tickets in a window.
     * Default window is calendar month-to-date (London) so glance "this month" matches the label.
     *
     * @param  list<array{status: string, resolutionTime: ?string, resolutionViolated: ?bool}>  $tickets
     * @param  'mtd'|'prev_month'|'30'  $window
     */
    private function slaMetPercent(array $tickets, string $window = 'mtd'): ?int
    {
        $sample = $this->slaSampleSize($tickets, $window);

        if ($sample === null || $sample === 0) {
            return null;
        }

        $met = 0;
        [$from, $to] = $this->resolutionWindowBounds($window);

        foreach ($tickets as $ticket) {
            if ($this->classifyStatus($ticket['status']) !== 'closed') {
                continue;
            }

            $resolved = $this->parseTimestamp($ticket['resolutionTime']);
            if ($resolved === null || ! $this->timestampInWindow($resolved, $from, $to)) {
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
     * @param  'mtd'|'prev_month'|'30'  $window
     */
    private function slaSampleSize(array $tickets, string $window = 'mtd'): ?int
    {
        [$from, $to] = $this->resolutionWindowBounds($window);
        $count = 0;

        foreach ($tickets as $ticket) {
            if ($this->classifyStatus($ticket['status']) !== 'closed') {
                continue;
            }

            $resolved = $this->parseTimestamp($ticket['resolutionTime']);
            if ($resolved === null || ! $this->timestampInWindow($resolved, $from, $to)) {
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
            '90' => $this->countCreatedSince($tickets, 90),
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
            '90' => $this->countClosedSince($tickets, 90),
            // Calendar months (Europe/London) - used by glance "this month" / last-month compare.
            'mtd' => $this->countClosedInWindow($tickets, 'mtd'),
            'prev_month' => $this->countClosedInWindow($tickets, 'prev_month'),
            'all' => $this->countClosedSince($tickets, null),
        ];
    }

    /**
     * @param  list<array{status: string, resolutionTime: ?string}>  $tickets
     * @param  'mtd'|'prev_month'  $window
     */
    private function countClosedInWindow(array $tickets, string $window): int
    {
        [$from, $to] = $this->resolutionWindowBounds($window);
        $count = 0;

        foreach ($tickets as $ticket) {
            if ($this->classifyStatus($ticket['status']) !== 'closed') {
                continue;
            }

            $resolved = $this->parseTimestamp($ticket['resolutionTime']);
            if ($resolved !== null && $this->timestampInWindow($resolved, $from, $to)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param  'mtd'|'prev_month'|'30'  $window
     * @return array{0: \Carbon\CarbonInterface, 1: \Carbon\CarbonInterface}
     */
    private function resolutionWindowBounds(string $window): array
    {
        $now = now()->timezone('Europe/London');

        return match ($window) {
            'prev_month' => [
                $now->copy()->subMonthNoOverflow()->startOfMonth()->startOfDay(),
                $now->copy()->subMonthNoOverflow()->endOfMonth()->endOfDay(),
            ],
            '30' => [
                $now->copy()->subDays(30)->startOfDay(),
                $now->copy()->endOfDay(),
            ],
            default => [
                $now->copy()->startOfMonth()->startOfDay(),
                $now->copy()->endOfDay(),
            ],
        };
    }

    private function timestampInWindow(
        \Carbon\CarbonInterface $at,
        \Carbon\CarbonInterface $from,
        \Carbon\CarbonInterface $to,
    ): bool {
        $at = $at->timezone('Europe/London');

        return $at->gte($from) && $at->lte($to);
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

        if (in_array($status, self::OPEN_STATUSES, true) || $this->isThirdPartyStatus($status) || $this->isWaitingOnClientStatus($status)) {
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
            slaMetPercentPrevMonth: isset($payload['sla_met_percent_prev_month']) && is_numeric($payload['sla_met_percent_prev_month'])
                ? (int) $payload['sla_met_percent_prev_month']
                : null,
            slaSampleSizePrevMonth: isset($payload['sla_sample_size_prev_month']) && is_numeric($payload['sla_sample_size_prev_month'])
                ? (int) $payload['sla_sample_size_prev_month']
                : null,
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
        return [
            '7' => null,
            '14' => null,
            '30' => null,
            '90' => null,
            'mtd' => null,
            'prev_month' => null,
            'all' => null,
        ];
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
            'age' => [
                'under_3' => 0,
                '3_to_5' => 0,
                'over_5' => 0,
                'unknown' => 0,
                'sources' => ['purchase' => 0, 'warranty' => 0, 'os_install' => 0, 'cpu' => 0],
            ],
            'standard' => ['checked' => 0, 'meets' => 0, 'below' => 0, 'pending' => 0, 'names' => []],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $assets
     * @param  array<string, mixed>  $hardware
     * @return array<string, mixed>
     */
    private function deviceInsightsFromAssets(array $assets, array $hardware = []): array
    {
        $insights = $this->emptyDeviceInsights();
        $offlineNames = [];
        $restartNames = [];
        $belowNames = [];
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

            $assetId = (string) ($asset['assetId'] ?? '');
            $sample = is_array($hardware[$assetId] ?? null) ? $hardware[$assetId] : [];
            $cpuName = trim((string) ($sample['cpu_name'] ?? ''));
            $ramGb = isset($sample['ram_gb']) && is_numeric($sample['ram_gb']) ? (float) $sample['ram_gb'] : null;
            $estimate = $this->devices->estimateDate(
                isset($asset['purchasedDate']) ? (string) $asset['purchasedDate'] : null,
                isset($asset['warrantyExpiryDate']) ? (string) $asset['warrantyExpiryDate'] : null,
                isset($sample['os_installed_at']) ? (string) $sample['os_installed_at'] : null,
                $cpuName !== '' ? $cpuName : null,
            );
            $band = $this->devices->ageBand($estimate['at']);
            $insights['age'][$band]++;
            if (isset($insights['age']['sources'][$estimate['source']])) {
                $insights['age']['sources'][$estimate['source']]++;
            }

            if ($this->isWorkstation($asset)) {
                if (($sample['checked_at'] ?? null) === null || ($sample['skipped'] ?? false) === true) {
                    $insights['standard']['pending']++;
                } else {
                    $assessment = $this->devices->assessWorkstation($cpuName !== '' ? $cpuName : null, $ramGb);
                    if ($assessment['cpu'] !== null || $assessment['ram'] !== null) {
                        $insights['standard']['checked']++;
                    }
                    if ($assessment['meets']) {
                        $insights['standard']['meets']++;
                    }
                    if ($assessment['below']) {
                        $insights['standard']['below']++;
                        $gap = $this->devices->gapLabel($assessment, $cpuName, $ramGb);
                        $belowNames[] = $gap !== '' ? $name.' - '.$gap : $name;
                    }
                }
            }
        }

        $insights['offline_30d']['names'] = array_values(array_slice($offlineNames, 0, self::DEVICE_NAME_LIMIT));
        $insights['needs_restart']['names'] = array_values(array_slice($restartNames, 0, self::DEVICE_NAME_LIMIT));
        $insights['standard']['names'] = array_values(array_slice($belowNames, 0, self::DEVICE_NAME_LIMIT));

        return $insights;
    }

    private function isWorkstation(array $asset): bool
    {
        $platform = strtolower((string) ($asset['platform'] ?? ''));
        $class = $this->normalizeJsonObject($asset['assetClass'] ?? null);
        $className = strtolower((string) ($class['name'] ?? ''));

        if (str_contains($platform, 'server') || str_contains($className, 'server')) {
            return false;
        }

        return $platform !== '' || $className !== '';
    }

    /**
     * A few hardware summaries per refresh, merged with the previous snapshot,
     * so a large estate is filled in across pulls instead of one long job.
     *
     * @param  list<array<string, mixed>>  $assets
     * @param  array<string, mixed>  $previous
     * @return array<string, mixed>
     */
    private function collectHardwareSamples(array $assets, array $previous): array
    {
        $hardware = [];
        foreach ($previous as $assetId => $row) {
            if (is_string($assetId) && is_array($row)) {
                $hardware[$assetId] = $row;
            }
        }

        $calls = 0;
        $freshBefore = now()->subDays(14);

        foreach ($assets as $asset) {
            if ($calls >= 12) {
                break;
            }

            $assetId = (string) ($asset['assetId'] ?? '');
            if ($assetId === '' || ! $this->isWorkstation($asset)) {
                continue;
            }

            $existing = is_array($hardware[$assetId] ?? null) ? $hardware[$assetId] : null;
            $checkedAt = $this->parseTimestamp(isset($existing['checked_at']) ? (string) $existing['checked_at'] : null);
            if ($existing !== null && $checkedAt !== null && $checkedAt->gte($freshBefore)) {
                continue;
            }

            $failedAt = $this->parseTimestamp(isset($existing['failed_at']) ? (string) $existing['failed_at'] : null);
            if ($failedAt !== null && $failedAt->gte(now()->subHour())) {
                continue;
            }

            try {
                $summary = $this->api->query(<<<'GQL'
                    query getAssetSummary($input: AssetIdentifierInput!) {
                        getAssetSummary(input: $input) {
                            cpu { cpuName }
                            memory { totalMemory }
                        }
                    }
                GQL, ['input' => ['assetId' => $assetId]]);
                $calls++;
                $mapped = $this->mapHardwareSummary($summary['getAssetSummary'] ?? []);
            } catch (Throwable $e) {
                Log::warning('SuperOps asset summary unavailable', [
                    'asset_id' => $assetId,
                    'error' => $e->getMessage(),
                ]);
                $hardware[$assetId] = [
                    'failed_at' => now()->toIso8601String(),
                ];
                if (str_contains(strtolower($e->getMessage()), 'rate_limit')) {
                    break;
                }

                continue;
            }

            $needsInstall = ! filled($asset['purchasedDate'] ?? null) && ! filled($asset['warrantyExpiryDate'] ?? null);
            if ($needsInstall && $calls < 12) {
                try {
                    $mapped['os_installed_at'] = $this->windowsInstallDate($assetId);
                    $calls++;
                } catch (Throwable $e) {
                    Log::warning('SuperOps asset software list unavailable', [
                        'asset_id' => $assetId,
                        'error' => $e->getMessage(),
                    ]);
                    if (str_contains(strtolower($e->getMessage()), 'rate_limit')) {
                        $hardware[$assetId] = $mapped;
                        break;
                    }
                }
            }

            $hardware[$assetId] = $mapped;
        }

        if (count($hardware) > 500) {
            $hardware = array_slice($hardware, -500, null, true);
        }

        return $hardware;
    }

    /**
     * @param  array<string, mixed>  $summary
     * @return array{cpu_name: string, ram_gb: ?float, os_installed_at: ?string, checked_at: string}
     */
    private function mapHardwareSummary(array $summary): array
    {
        $cpu = $summary['cpu'] ?? [];
        if (is_array($cpu) && array_is_list($cpu)) {
            $cpu = $cpu[0] ?? [];
        }

        return [
            'cpu_name' => trim((string) (is_array($cpu) ? ($cpu['cpuName'] ?? '') : '')),
            'ram_gb' => $this->memoryGb(is_array($summary['memory'] ?? null) ? ($summary['memory']['totalMemory'] ?? null) : null),
            'os_installed_at' => null,
            'checked_at' => now()->toIso8601String(),
        ];
    }

    private function memoryGb(mixed $total): ?float
    {
        if (is_numeric($total)) {
            return round(((float) $total) / 1073741824, 2);
        }

        if (! is_array($total)) {
            return null;
        }

        if (isset($total['actualValue']) && is_numeric($total['actualValue'])) {
            return round(((float) $total['actualValue']) / 1073741824, 2);
        }

        if (! isset($total['value']) || ! is_numeric($total['value'])) {
            return null;
        }

        $value = (float) $total['value'];

        return match (strtoupper((string) ($total['unit'] ?? 'GB'))) {
            'TB' => round($value * 1024, 2),
            'MB' => round($value / 1024, 2),
            'KB' => round($value / 1048576, 2),
            default => round($value, 2),
        };
    }

    private function windowsInstallDate(string $assetId): ?string
    {
        $data = $this->api->query(<<<'GQL'
            query getAssetSoftwareList($input: AssetDetailsListInput!) {
                getAssetSoftwareList(input: $input) {
                    assetSoftwares { installedDate software }
                }
            }
        GQL, ['input' => ['assetId' => $assetId, 'listInfo' => ['page' => 1, 'pageSize' => 50]]]);

        $rows = $data['getAssetSoftwareList']['assetSoftwares'] ?? [];
        if (! is_array($rows)) {
            return null;
        }

        $earliest = null;
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $name = $this->softwareName($row['software'] ?? null);
            if (! preg_match('/microsoft windows|\bwindows\s*(10|11)\b/i', $name)) {
                continue;
            }

            if (preg_match('/defender|installer|media|sdk|driver|update|security/i', $name)) {
                continue;
            }

            $installed = $this->parseTimestamp(isset($row['installedDate']) ? (string) $row['installedDate'] : null);
            if ($installed === null) {
                continue;
            }

            if ($earliest === null || $installed->lt($earliest)) {
                $earliest = $installed;
            }
        }

        return $earliest?->toIso8601String();
    }

    private function softwareName(mixed $software): string
    {
        if (is_string($software) && $software !== '') {
            $decoded = json_decode($software, true);
            if (is_array($decoded)) {
                $software = $decoded;
            } else {
                return $software;
            }
        }

        if (! is_array($software)) {
            return '';
        }

        return trim((string) ($software['name'] ?? ''));
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
        // v7: warranty / OS install / CPU age, workstation standard, 90-day ticket counts.
        return "client:{$clientId}:superops-dashboard:v7";
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

        return \App\Support\QueuedJobPayload::whereClientId(
            \Illuminate\Support\Facades\DB::table('jobs')->where('payload', 'like', '%RefreshSuperOpsDashboardJob%'),
            $clientId,
        )->exists();
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
