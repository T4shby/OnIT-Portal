<?php

namespace App\Services\SuperOps;

use App\Jobs\RefreshSuperOpsDashboardJob;
use App\Models\Client;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class SuperOpsClientMetricsService
{
    /**
     * Statuses treated as open. Verified against live SuperOps ticket data (2026-07-15).
     *
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
     * Statuses treated as closed. Verified against live SuperOps ticket data (2026-07-15).
     *
     * @var list<string>
     */
    public const CLOSED_STATUSES = [
        'Closed',
        'Closed (no response)',
        'Resolved',
        'Cancelled',
    ];

    public function __construct(private SuperOpsApiClient $api) {}

    public function isAvailable(): bool
    {
        return $this->api->isConfigured();
    }

    public function summaryForClient(Client $client, bool $manualRefresh = false): ClientOperationsSummary
    {
        if (! $this->isAvailable()) {
            return $this->unavailableSummary('SuperOps API is not configured.');
        }

        if (empty($client->superops_account_id)) {
            return $this->unavailableSummary('SuperOps is not connected for this organisation.');
        }

        $cacheKey = $this->cacheKey($client->id);

        if ($manualRefresh) {
            $this->queueRefresh($client);
        } else {
            $cached = Cache::get($cacheKey);
            if (is_array($cached)) {
                $summary = $this->summaryFromCache($client->id, $cached);
                if ($summary->isStale && ! $summary->refreshInProgress) {
                    $this->queueRefresh($client);
                }

                return $summary;
            }
        }

        $this->queueRefresh($client);

        $stale = Cache::get($cacheKey);

        if (is_array($stale)) {
            return $this->summaryFromCache($client->id, $stale, refreshInProgress: true);
        }

        return new ClientOperationsSummary(
            assetsTotal: null,
            openTicketsTotal: null,
            ticketsCreated: $this->emptyRangeCounts(),
            ticketsClosed: $this->emptyRangeCounts(),
            lastRefreshedAt: null,
            isStale: true,
            refreshInProgress: true,
            unavailableReason: 'Data has not been synchronised yet.',
        );
    }

    public function queueRefresh(Client $client, bool $respectCooldown = false): bool
    {
        if (empty($client->superops_account_id) || ! $this->isAvailable()) {
            return false;
        }

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

        Cache::put('superops_dashboard.refresh_queued.'.$client->id, true, now()->addMinutes(30));
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
            $tickets = $this->listAllClientTickets($accountId);
            $payload = [
                'assets_total' => $this->countAssets($accountId),
                'open_tickets_total' => $this->countOpenTickets($tickets),
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
                'error' => $e->getMessage(),
            ]);

            $cached = Cache::get($this->cacheKey($client->id));
            if (is_array($cached)) {
                return $this->summaryFromCache($client->id, $cached, isStale: true);
            }

            throw $e;
        } finally {
            Cache::forget('superops_dashboard.refresh_queued.'.$client->id);
            $lock->release();
        }
    }

    private function countAssets(string $accountId): ?int
    {
        $data = $this->api->query(<<<'GQL'
            query getAssetList($input: ListInfoInput!) {
                getAssetList(input: $input) {
                    listInfo { totalCount }
                }
            }
        GQL, [
            'input' => [
                'page' => 1,
                'pageSize' => 1,
                'condition' => [
                    'attribute' => 'client.accountId',
                    'operator' => 'is',
                    'value' => $accountId,
                ],
            ],
        ]);

        return isset($data['getAssetList']['listInfo']['totalCount'])
            ? (int) $data['getAssetList']['listInfo']['totalCount']
            : null;
    }

    /**
     * @return list<array{status: string, createdTime: ?string, resolutionTime: ?string}>
     */
    private function listAllClientTickets(string $accountId): array
    {
        $tickets = [];
        $page = 1;
        $total = null;

        do {
            $data = $this->api->query(<<<'GQL'
                query getTicketList($input: ListInfoInput!) {
                    getTicketList(input: $input) {
                        tickets { status createdTime resolutionTime }
                        listInfo { totalCount page pageSize }
                    }
                }
            GQL, [
                'input' => [
                    'page' => $page,
                    'pageSize' => 100,
                    'condition' => [
                        'attribute' => 'client.accountId',
                        'operator' => 'is',
                        'value' => $accountId,
                    ],
                ],
            ]);

            $batch = $data['getTicketList']['tickets'] ?? [];
            $total = (int) ($data['getTicketList']['listInfo']['totalCount'] ?? count($batch));

            foreach ($batch as $ticket) {
                $tickets[] = [
                    'status' => $this->statusName($ticket['status'] ?? null),
                    'createdTime' => $ticket['createdTime'] ?? null,
                    'resolutionTime' => $ticket['resolutionTime'] ?? null,
                ];
            }

            $page++;
        } while (count($tickets) < $total && $batch !== [] && $page <= 100);

        return $tickets;
    }

    /**
     * @param  list<array{status: string, createdTime: ?string, resolutionTime: ?string}>  $tickets
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
     * @param  list<array{status: string, createdTime: ?string, resolutionTime: ?string}>  $tickets
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
     * @param  list<array{status: string, createdTime: ?string, resolutionTime: ?string}>  $tickets
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
                (int) config('services.superops.dashboard_cache_minutes', 10),
            ));
        }

        return new ClientOperationsSummary(
            assetsTotal: $payload['assets_total'] ?? null,
            openTicketsTotal: $payload['open_tickets_total'] ?? null,
            ticketsCreated: $payload['tickets_created'] ?? $this->emptyRangeCounts(),
            ticketsClosed: $payload['tickets_closed'] ?? $this->emptyRangeCounts(),
            lastRefreshedAt: $lastRefreshedAt,
            isStale: $isStale,
            refreshInProgress: $refreshInProgress || Cache::has('superops_dashboard.refresh_queued.'.$clientId),
        );
    }

    private function unavailableSummary(string $reason): ClientOperationsSummary
    {
        return new ClientOperationsSummary(
            assetsTotal: null,
            openTicketsTotal: null,
            ticketsCreated: $this->emptyRangeCounts(),
            ticketsClosed: $this->emptyRangeCounts(),
            lastRefreshedAt: null,
            isStale: false,
            refreshInProgress: false,
            unavailableReason: $reason,
        );
    }

    /**
     * @return array<string, null>
     */
    private function emptyRangeCounts(): array
    {
        return ['7' => null, '14' => null, '30' => null, 'all' => null];
    }

    private function cacheKey(int $clientId): string
    {
        return "client:{$clientId}:superops-dashboard:v1";
    }
}
