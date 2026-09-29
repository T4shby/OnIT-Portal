<?php

namespace App\Services\Admin;

use App\Models\Client;
use App\Services\EntraSync\MicrosoftGraphClient;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Technician-only view of per-client background refresh health + pipeline visibility.
 * Not shown to client_admin / end customers.
 */
class IntegrationHealthService
{
    /** Consider a queued flag "stuck" after this many minutes. */
    public const STUCK_AFTER_MINUTES = 5;

    public const PREWARM_CACHE_KEY = 'portal.prewarm.last_run';

    /** Rows scanned before per-client filtering of the queue panel for scoped (non-super-admin) viewers. */
    private const SCOPED_QUEUE_SCAN_LIMIT = 500;

    /** Last time portal:sync-entra-users was invoked by the scheduler (or manually). */
    public const ENTRA_SCHEDULE_HEARTBEAT_KEY = 'portal.entra_schedule.last_run';

    public const SCHEDULER_TICK_KEY = 'portal.scheduler.last_tick';

    /**
     * Job class fragment used to match database queue payloads.
     *
     * @var array<string, string>
     */
    private const JOB_CLASS_HINT = [
        'superops' => 'RefreshSuperOpsDashboardJob',
        'm365_directory' => 'RefreshM365DirectoryJob',
        'm365_insights' => 'RefreshM365InsightsJob',
        'entra_sync' => 'SyncEntraClientJob',
        'huntress' => 'RefreshHuntressSecurityJob',
        'dropsuite' => 'RefreshDropsuiteBackupJob',
    ];

    /**
     * Per-client table column labels (order = Integration Health UI order).
     * Keep in sync with clientRow() builders + JOB_CLASS_HINT for prewarmed feeds.
     *
     * @var array<string, string>
     */
    public const FEED_COLUMNS = [
        'superops' => 'Devices & tickets',
        'superops_scim' => 'SuperOps SCIM',
        'm365_directory' => 'M365 people',
        'm365_insights' => 'M365 licences',
        'entra_sync' => 'Entra sync',
        'huntress' => 'Huntress',
        'dropsuite' => 'Dropsuite',
    ];

    /**
     * @param  list<int>|null  $accessibleClientIds  null = all clients (console / internal callers);
     *                                                   an empty list means the viewer may see no clients
     *                                                   (e.g. an account manager with no assignments) - never "all".
     * @return array{
     *     queue: array<string, mixed>,
     *     pipeline: array<string, mixed>,
     *     notices: list<string>,
     *     clients: list<array<string, mixed>>,
     *     feed_columns: array<string, string>,
     *     stuck_count: int,
     *     aging_count: int,
     *     due_count: int,
     *     cleared_orphans: int,
     * }
     */
    public function overview(?array $accessibleClientIds = null): array
    {
        $clearedOrphans = 0;

        $clients = Client::query()
            ->where('is_active', true)
            ->when(
                $accessibleClientIds !== null,
                fn ($q) => $q->whereIn('id', $accessibleClientIds),
            )
            ->orderBy('name')
            ->get();

        $rows = $clients->map(function (Client $client) use (&$clearedOrphans): array {
            return $this->clientRow($client, $clearedOrphans);
        })->values()->all();

        $stuckCount = collect($rows)->where('is_stuck', true)->count();
        $agingCount = collect($rows)->sum(fn (array $row): int => (int) ($row['aging_count'] ?? 0));
        $dueCount = collect($rows)->sum(fn (array $row): int => (int) ($row['due_count'] ?? 0));
        $coldCount = collect($rows)->sum(fn (array $row): int => (int) ($row['cold_count'] ?? 0));

        // Queue totals are portfolio-wide aggregates, but the job / failure
        // rows name clients and carry error text, so they are scoped too.
        $queue = $this->queueSummary($accessibleClientIds);
        $pipeline = $this->pipelineSummary($queue, $coldCount);
        $notices = $this->buildNotices($pipeline, $rows, $clearedOrphans, $stuckCount, $agingCount, $dueCount, $coldCount);

        return [
            'queue' => $queue,
            'pipeline' => $pipeline,
            'notices' => $notices,
            'clients' => $rows,
            'feed_columns' => self::FEED_COLUMNS,
            'stuck_count' => $stuckCount,
            'aging_count' => $agingCount,
            'due_count' => $dueCount,
            'cold_count' => $coldCount,
            'cleared_orphans' => $clearedOrphans,
        ];
    }

    /**
     * Portfolio KPI: entitlement / live coverage across sold service feeds (not licence vendors).
     *
     * Live = feed has a snapshot age (not cold/disabled). Setup = entitled but setup-needed cells.
     * Cold = sold + mapped pathway but never loaded. KPI target: cold_cells → 0.
     *
     * @param  list<int>|null  $accessibleClientIds
     * @return array{
     *   clients: int,
     *   sold_feed_cells: int,
     *   live_feed_cells: int,
     *   setup_feed_cells: int,
     *   cold_feed_cells: int,
     *   failed_feed_cells: int,
     *   live_pct: float|null,
     *   cold_pct: float|null,
     *   rows: list<array{client_id: int, client_name: string, sold: int, live: int, setup: int, cold: int, failed: int}>
     * }
     */
    public function productCoverage(?array $accessibleClientIds = null): array
    {
        $overview = $this->overview($accessibleClientIds);
        $rows = [];
        $sold = 0;
        $live = 0;
        $setup = 0;
        $cold = 0;
        $failed = 0;

        foreach ($overview['clients'] as $clientRow) {
            $rowSold = 0;
            $rowLive = 0;
            $rowSetup = 0;
            $rowCold = 0;
            $rowFailed = 0;

            foreach ($clientRow['integrations'] ?? [] as $cell) {
                $status = (string) ($cell['status'] ?? '');
                $detail = strtolower((string) ($cell['detail'] ?? $cell['what_it_is_doing'] ?? ''));

                if ($status === 'disabled') {
                    if (str_contains($detail, 'not sold')) {
                        continue;
                    }
                    // Entitled but unmapped / platform off / disabled API → setup attention.
                    $rowSetup++;
                    $setup++;
                    $rowSold++;
                    $sold++;

                    continue;
                }

                $rowSold++;
                $sold++;

                if ($status === 'cold') {
                    $rowCold++;
                    $cold++;
                } elseif (in_array($status, ['failed', 'stuck'], true)) {
                    $rowFailed++;
                    $failed++;
                } else {
                    // ok, due, aging, running, queued - treat as live path for coverage %
                    $rowLive++;
                    $live++;
                }
            }

            if ($rowSold === 0) {
                continue;
            }

            $rows[] = [
                'client_id' => (int) ($clientRow['client_id'] ?? 0),
                'client_name' => (string) ($clientRow['client_name'] ?? 'Client'),
                'sold' => $rowSold,
                'live' => $rowLive,
                'setup' => $rowSetup,
                'cold' => $rowCold,
                'failed' => $rowFailed,
            ];
        }

        return [
            'clients' => count($rows),
            'sold_feed_cells' => $sold,
            'live_feed_cells' => $live,
            'setup_feed_cells' => $setup,
            'cold_feed_cells' => $cold,
            'failed_feed_cells' => $failed,
            'live_pct' => $sold > 0 ? round(($live / $sold) * 100, 1) : null,
            'cold_pct' => $sold > 0 ? round(($cold / $sold) * 100, 1) : null,
            'rows' => $rows,
        ];
    }

    /**
     * @return array{
     *     pending: int,
     *     failed: int,
     *     high: int,
     *     default: int,
     *     reserved: int,
     *     oldest_pending_seconds: ?int,
     *     jobs: list<array<string, mixed>>,
     *     recent_failures: list<array<string, mixed>>,
     * }
     */
    public function queueSummary(?array $accessibleClientIds = null): array
    {
        if (! Schema::hasTable('jobs')) {
            return [
                'pending' => 0,
                'failed' => 0,
                'high' => 0,
                'default' => 0,
                'reserved' => 0,
                'oldest_pending_seconds' => null,
                'jobs' => [],
                'recent_failures' => [],
            ];
        }

        $pending = (int) DB::table('jobs')->count();
        $high = (int) DB::table('jobs')->where('queue', 'high')->count();
        $default = (int) DB::table('jobs')->where('queue', 'default')->count();
        $reserved = (int) DB::table('jobs')->whereNotNull('reserved_at')->count();
        $oldest = DB::table('jobs')->min('created_at');
        $oldestSeconds = $oldest !== null ? max(0, time() - (int) $oldest) : null;

        $failed = Schema::hasTable('failed_jobs')
            ? (int) DB::table('failed_jobs')->count()
            : 0;

        return [
            'pending' => $pending,
            'failed' => $failed,
            'high' => $high,
            'default' => $default,
            'reserved' => $reserved,
            'oldest_pending_seconds' => $oldestSeconds,
            'jobs' => $this->listJobs(20, $accessibleClientIds),
            'recent_failures' => $this->recentFailures(8, $accessibleClientIds),
        ];
    }

    /**
     * @param  array<string, mixed>  $queue
     * @return array<string, mixed>
     */
    private function pipelineSummary(array $queue, int $coldCount = 0): array
    {
        $prewarm = Cache::get(self::PREWARM_CACHE_KEY);
        $prewarmAt = is_array($prewarm) && filled($prewarm['at'] ?? null)
            ? Carbon::parse($prewarm['at'])
            : null;
        $prewarmAgeSeconds = $prewarmAt?->diffInSeconds(now());
        $prewarmAgeMinutes = $prewarmAgeSeconds !== null ? (int) round($prewarmAgeSeconds / 60) : null;

        $tickRaw = Cache::get(self::SCHEDULER_TICK_KEY);
        $tickAt = filled($tickRaw) ? Carbon::parse($tickRaw) : null;
        $tickAgeSeconds = $tickAt?->diffInSeconds(now());
        $tickAgeMinutes = $tickAgeSeconds !== null ? (int) round($tickAgeSeconds / 60) : null;

        $freshness = app(\App\Services\Portal\PortalFreshnessService::class)->snapshot();
        $superOpsRequeueAfter = (float) $freshness['requeue_minutes'];
        $superOpsClientWindow = (float) $freshness['soft_window_minutes'];
        $prewarmIntervalMinutes = (float) $freshness['interval_minutes'];
        // Late if more than ~2.2× the current target cadence (e.g. ~5.5m when hot 2.5m; ~2h when hour idle).
        $prewarmLateAfterSeconds = (int) round($prewarmIntervalMinutes * 60 * 2.2);

        $workerLagSuspect = ($queue['pending'] ?? 0) > 0
            && ($queue['reserved'] ?? 0) === 0
            && ($queue['oldest_pending_seconds'] ?? 0) >= 90;

        $scheduleLocks = $this->scheduleLockRows();
        // True problems only: expired mutex rows still in the table, or a long-lived
        // schedule mutex while the minute tick is late (process died mid withoutOverlapping).
        // Do NOT treat "TTL remaining > 5m" alone as stuck - Laravel withoutOverlapping
        // often sets expiry ~24h ahead, so healthy locks always look "long-held".
        $stuckScheduleLocks = collect($scheduleLocks)
            ->filter(function (array $lock) use ($tickAgeMinutes): bool {
                if (! empty($lock['is_expired'])) {
                    return true;
                }

                $tickLate = $tickAgeMinutes === null || $tickAgeMinutes > 5;
                $longTtl = ($lock['expires_in_seconds'] ?? 0) >= 600;

                return $tickLate && $longTtl;
            })
            ->values()
            ->all();

        $schedulerOk = $tickAt !== null && ($tickAgeMinutes ?? 99) <= 2;
        $prewarmOk = $prewarmAt !== null && ($prewarmAgeSeconds ?? 99999) <= $prewarmLateAfterSeconds;

        $headline = 'All systems refreshing normally';
        $severityLevel = 'ok';
        if (! $schedulerOk) {
            $headline = 'Minute scheduler is not ticking - cron schedule:run may be dead';
            $severityLevel = 'critical';
        } elseif ($stuckScheduleLocks !== []) {
            $headline = 'Schedule mutex problem - expired lock left behind or lock held while cron tick is late';
            $severityLevel = 'warning';
        } elseif (! $prewarmOk) {
            $headline = 'Auto-refresh (prewarm) is late - client data will age until it runs again';
            $severityLevel = 'warning';
        } elseif ($workerLagSuspect) {
            $headline = 'Jobs are waiting but no worker is processing them';
            $severityLevel = 'critical';
        } elseif ($coldCount > 0) {
            $headline = $coldCount === 1
                ? '1 sold integration has never loaded a snapshot - prewarm/workers should fill it'
                : "{$coldCount} sold integration feeds have never loaded a snapshot - check prewarm, workers, and mapping IDs";
            $severityLevel = 'warning';
        } elseif (($queue['pending'] ?? 0) > 0) {
            $headline = 'Refresh jobs are in the queue and should finish shortly';
            $severityLevel = 'info';
        }

        return [
            'generated_at' => now(),
            'headline' => $headline,
            'severity_level' => $severityLevel,
            'cold_count' => $coldCount,
            'superops_requeue_after_minutes' => $superOpsRequeueAfter,
            'superops_client_window_minutes' => $superOpsClientWindow,
            'freshness' => $freshness,
            'scheduler' => [
                'last_at' => $tickAt,
                'age_minutes' => $tickAgeMinutes,
                'ok' => $schedulerOk,
                'never' => $tickAt === null,
            ],
            'prewarm' => [
                'last_at' => $prewarmAt,
                'age_minutes' => $prewarmAgeMinutes,
                'superops_queued' => is_array($prewarm) ? (int) ($prewarm['superops_queued'] ?? 0) : null,
                'optional_queued' => is_array($prewarm) ? (int) ($prewarm['optional_queued'] ?? 0) : null,
                'cold_optional_queued' => is_array($prewarm) ? (int) ($prewarm['cold_optional_queued'] ?? 0) : null,
                'clients' => is_array($prewarm) ? (int) ($prewarm['clients'] ?? 0) : null,
                'queue_deep' => is_array($prewarm) ? (bool) ($prewarm['queue_deep'] ?? false) : null,
                'pending_before' => is_array($prewarm) ? (int) ($prewarm['pending_before'] ?? 0) : null,
                'interval_minutes' => $prewarmIntervalMinutes,
                'overdue' => ! $prewarmOk,
                'never_ran' => $prewarmAt === null,
                'ok' => $prewarmOk,
            ],
            'workers' => [
                'expect' => 'Every minute: two queue:work processes on high,default',
                'lag_suspect' => $workerLagSuspect,
                'reserved' => (int) ($queue['reserved'] ?? 0),
                'pending' => (int) ($queue['pending'] ?? 0),
                'oldest_pending_seconds' => $queue['oldest_pending_seconds'] ?? null,
                'ok' => ! $workerLagSuspect,
            ],
            'schedule_locks' => $scheduleLocks,
            'stuck_schedule_locks' => $stuckScheduleLocks,
        ];
    }

    /**
     * Laravel schedule withoutOverlapping rows only (not every cache lock in the app).
     *
     * @return list<array{
     *   key: string,
     *   expiration: int,
     *   is_expired: bool,
     *   expires_in_seconds: int,
     *   expired_for_seconds: int,
     *   held_for_seconds: int
     * }>
     */
    private function scheduleLockRows(): array
    {
        if (! Schema::hasTable('cache_locks')) {
            return [];
        }

        $now = time();

        return DB::table('cache_locks')
            ->where(function ($q): void {
                $q->where('key', 'like', '%framework/schedule%')
                    ->orWhere('key', 'like', '%schedule-%');
            })
            ->orderBy('key')
            ->get()
            ->map(function ($row) use ($now): array {
                $exp = (int) ($row->expiration ?? 0);
                $expired = $exp > 0 && $exp <= $now;
                $expiresIn = $exp > $now ? ($exp - $now) : 0;
                $expiredFor = $expired ? ($now - $exp) : 0;

                return [
                    'key' => (string) $row->key,
                    'expiration' => $exp,
                    'is_expired' => $expired,
                    'expires_in_seconds' => $expiresIn,
                    'expired_for_seconds' => $expiredFor,
                    // Kept for BC in views - meaning: remaining TTL while active; age when expired.
                    'held_for_seconds' => $expired ? $expiredFor : $expiresIn,
                    'seconds_until_release' => $expiresIn,
                ];
            })
            ->all();
    }

    /**
     * @param  array<string, mixed>  $pipeline
     * @param  list<array<string, mixed>>  $rows
     * @return list<string>
     */
    private function buildNotices(
        array $pipeline,
        array $rows,
        int $clearedOrphans,
        int $stuckCount,
        int $agingCount,
        int $dueCount,
        int $coldCount = 0,
    ): array {
        $notices = [];

        $scheduler = $pipeline['scheduler'] ?? [];
        if ($scheduler['never'] ?? false) {
            $notices[] = 'Scheduler tick never recorded. Root crontab must run schedule:run every minute.';
        } elseif (! ($scheduler['ok'] ?? false)) {
            $age = $scheduler['age_minutes'] ?? '?';
            $notices[] = "Scheduler tick is {$age}m old (expect under 2m). Cron may have stopped.";
        }

        if (! empty($pipeline['stuck_schedule_locks'])) {
            $n = count($pipeline['stuck_schedule_locks']);
            $notices[] = "{$n} schedule mutex issue(s): expired lock left in cache_locks, or a long withoutOverlapping lock while the minute tick is late. Healthy withoutOverlapping locks are normal during schedule:run - they are not failures. Expired rows clear on next prewarm.";
        }

        if ($pipeline['prewarm']['never_ran'] ?? false) {
            $notices[] = 'Prewarm has never recorded a run. Auto client metrics will stay empty/old.';
        } elseif ($pipeline['prewarm']['overdue'] ?? false) {
            $age = $pipeline['prewarm']['age_minutes'] ?? '?';
            $notices[] = "Prewarm last ran {$age}m ago (expect every ~".($pipeline['prewarm']['interval_minutes'] ?? 2.5).'m). Until it runs, aging data will not auto-reset.';
        }

        if ($pipeline['workers']['lag_suspect'] ?? false) {
            $oldest = $pipeline['workers']['oldest_pending_seconds'] ?? 0;
            $pending = $pipeline['workers']['pending'] ?? 0;
            $notices[] = "{$pending} job(s) waiting with nothing reserved - workers not draining. Oldest ~".(int) round($oldest / 60).'m.';
        }

        if ($clearedOrphans > 0) {
            $notices[] = "Cleared {$clearedOrphans} orphaned 'queued' flag(s) (said queued but no job row).";
        }

        if ($stuckCount > 0) {
            $notices[] = "{$stuckCount} refresh job(s) stuck (running over ".self::STUCK_AFTER_MINUTES.' minutes).';
        }

        if ($coldCount > 0) {
            $notices[] = "{$coldCount} sold integration feed(s) never loaded a snapshot (Never loaded). Prewarm should queue cold pulls; if this persists check mapping IDs, platform tokens, and that queue depth is not skipping optional feeds.";
        }

        if ($agingCount > 0) {
            $notices[] = "{$agingCount} data source(s) past freshness - clients may see softer wording.";
        }

        if ($dueCount > 0) {
            $requeue = (float) ($pipeline['superops_requeue_after_minutes']
                ?? ($pipeline['freshness']['requeue_minutes'] ?? null)
                ?? ($pipeline['freshness']['interval_minutes'] ?? 2.5));
            $interval = (float) ($pipeline['prewarm']['interval_minutes']
                ?? ($pipeline['freshness']['interval_minutes'] ?? 2.5));
            $prewarmAge = $pipeline['prewarm']['age_minutes'] ?? '?';
            $requeueLabel = rtrim(rtrim(number_format($requeue, 1), '0'), '.');
            $intervalLabel = rtrim(rtrim(number_format($interval, 1), '0'), '.');
            if ($pipeline['prewarm']['ok'] ?? false) {
                // Idle cadence intentionally leaves a short “due” window before the next prewarm.
                $notices[] = "{$dueCount} feed(s) past ~{$requeueLabel}m requeue age - normal until next prewarm "
                    ."(~every {$intervalLabel}m; last ran {$prewarmAge}m ago). Workers stay idle with an empty queue until then.";
            } else {
                $notices[] = "{$dueCount} feed(s) past ~{$requeueLabel}m requeue age and prewarm is late "
                    ."(last ran {$prewarmAge}m ago; expect ~{$intervalLabel}m) - SuperOps, M365, Huntress, Dropsuite will age until prewarm runs.";
            }
        }

        foreach ($rows as $row) {
            foreach ($row['integrations'] as $cell) {
                if (($cell['status'] ?? '') === 'failed') {
                    $msg = filled($cell['error'] ?? null)
                        ? $cell['error']
                        : ($cell['blockers'][0] ?? $cell['what_it_is_doing'] ?? 'Check configuration');
                    $notices[] = "{$row['client_name']}: {$cell['friendly_label']} - {$msg}";
                }
                if (($cell['status'] ?? '') === 'cold') {
                    $notices[] = "{$row['client_name']}: {$cell['label']} never loaded - no successful cache yet.";
                }
            }
        }

        if ($notices === []) {
            $notices[] = 'Nothing blocking auto-refresh right now.';
        }

        return array_values(array_unique($notices));
    }

    /**
     * @return array<string, mixed>
     */
    public function clientRow(Client $client, int &$clearedOrphans = 0): array
    {
        $products = app(\App\Services\Portal\ClientProductService::class);

        $integrations = [
            $this->superOps($client, $clearedOrphans),
        ];

        if ($products->isEntitled($client, 'superops')
            && $client->entra_sync_enabled
            && filled($client->entra_tenant_id)) {
            $integrations[] = $this->superOpsScimExport($client);
        }

        $integrations = array_merge($integrations, [
            $this->m365Directory($client, $clearedOrphans),
            $this->m365Insights($client, $clearedOrphans),
            $this->entraSync($client, $clearedOrphans),
            $this->huntress($client, $clearedOrphans),
            $this->dropsuite($client, $clearedOrphans),
        ]);

        $active = collect($integrations)->first(
            fn (array $row): bool => in_array($row['status'] ?? '', ['running', 'queued', 'stuck'], true),
        );

        $stuck = collect($integrations)->contains(
            fn (array $row): bool => ($row['status'] ?? '') === 'stuck',
        );

        $agingCount = collect($integrations)->where('status', 'aging')->count();
        $dueCount = collect($integrations)->where('status', 'due')->count();
        $coldCount = collect($integrations)->where('status', 'cold')->count();
        $failedCount = collect($integrations)->where('status', 'failed')->count();

        $worstAgeMinutes = collect($integrations)
            ->pluck('age_minutes')
            ->filter(fn ($v) => $v !== null)
            ->max();

        $blockers = collect($integrations)
            ->flatMap(fn (array $cell): array => $cell['blockers'] ?? [])
            ->values()
            ->all();

        return [
            'client_id' => $client->id,
            'client_name' => $client->name,
            'is_stuck' => $stuck,
            'active_process' => $active['label'] ?? null,
            'active_status' => $active['status'] ?? null,
            'active_detail' => $active['detail'] ?? null,
            'worst_age_minutes' => $worstAgeMinutes,
            'aging_count' => $agingCount,
            'due_count' => $dueCount,
            'cold_count' => $coldCount,
            'failed_count' => $failedCount,
            'blockers' => $blockers,
            'integrations' => $integrations,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function superOps(Client $client, int &$clearedOrphans): array
    {
        $products = app(\App\Services\Portal\ClientProductService::class);
        if (! $products->isEntitled($client, 'superops')) {
            return $this->disabled('superops', 'SuperOps dashboard', 'Not sold');
        }

        if (! filled($client->superops_account_id)) {
            return $this->disabled('superops', 'SuperOps dashboard', 'Setup needed');
        }

        $payload = Cache::get(app(\App\Services\SuperOps\SuperOpsClientMetricsService::class)->cacheKey($client->id));
        $last = is_array($payload) && filled($payload['last_refreshed_at'] ?? null)
            ? Carbon::parse($payload['last_refreshed_at'])
            : null;
        $result = Cache::get('superops_dashboard.last_result.'.$client->id);
        $requeueAfter = max(0.5, app(\App\Services\Portal\PortalFreshnessService::class)->effectiveRequeueMinutes());
        $clientWindow = max(1, app(\App\Services\Portal\PortalFreshnessService::class)->effectiveSoftWindowMinutes());

        return $this->integrationStatus(
            key: 'superops',
            label: 'SuperOps dashboard',
            clientId: $client->id,
            queuedKey: 'superops_dashboard.refresh_queued.'.$client->id,
            startedKey: 'superops_dashboard.refresh_started.'.$client->id,
            lastSuccessAt: $last,
            lastResult: is_array($result) ? $result : null,
            processHint: 'RefreshSuperOpsDashboardJob (GraphQL tickets + assets)',
            requeueAfterMinutes: $requeueAfter,
            clientWindowMinutes: $clientWindow,
            clearedOrphans: $clearedOrphans,
        );
    }

    /**
     * Entra → SuperOps SCIM export (Sync 1). Separate from SuperOps GraphQL dashboard refresh.
     *
     * @return array<string, mixed>
     */
    private function superOpsScimExport(Client $client): array
    {
        $key = 'superops_scim';
        $label = 'SuperOps SCIM export';

        if (! filled($client->entra_superops_app_id)) {
            return $this->scimHealthCell(
                key: $key,
                label: $label,
                status: 'failed',
                statusLabel: 'Setup needed',
                whatItIsDoing: 'Bootstrap incomplete - no SuperOps SCIM Application (client) ID saved.',
                whatNext: 'Connect Microsoft on Edit Client, then Apply SCIM (step 07).',
                blockers: ['SuperOps SCIM: Connect / bootstrap first, then Apply SCIM with Tenant URL + Secret.'],
                error: 'SuperOps SCIM Application ID not saved',
            );
        }

        $health = Cache::remember('scim.health.'.$client->id, now()->addMinutes(5), function () use ($client): array {
            return app(MicrosoftGraphClient::class)->getSuperOpsScimProvisioningHealth(
                (string) $client->entra_tenant_id,
                (string) $client->entra_superops_app_id,
            );
        });

        if (filled($health['error'] ?? null)) {
            return $this->scimHealthCell(
                key: $key,
                label: $label,
                status: 'failed',
                statusLabel: 'Check failed',
                whatItIsDoing: (string) $health['error'],
                whatNext: 'Fix Graph consent / tenant link, then refresh Integration Health.',
                blockers: ['SuperOps SCIM: '.(string) $health['error']],
                error: (string) $health['error'],
            );
        }

        if ($health['needsApplyScim'] ?? false) {
            return $this->scimHealthCell(
                key: $key,
                label: $label,
                status: 'failed',
                statusLabel: 'Setup needed',
                whatItIsDoing: 'SuperOps Tenant URL not stored in Entra - requesters will not export.',
                whatNext: 'Edit Client → Apply SCIM with Tenant URL + Secret Token (SuperOps step 05).',
                blockers: ['SuperOps SCIM: Apply SCIM credentials - Sync 2 alone does not create SuperOps requesters.'],
                error: 'Apply SCIM not completed',
            );
        }

        if ($health['needsRepair'] ?? false) {
            $hint = ($health['warnings'][0] ?? null) ?: 'No active provisioning job in Entra.';

            return $this->scimHealthCell(
                key: $key,
                label: $label,
                status: 'failed',
                statusLabel: 'Export stopped',
                whatItIsDoing: $hint,
                whatNext: 'Customer Entra → SuperOps app → Provisioning → Start, or php artisan portal:repair-superops-scim --client='.$client->id,
                blockers: ['SuperOps SCIM export stopped - '.$hint],
                error: 'SCIM provisioning job missing or not running',
            );
        }

        $jobState = filled($health['jobState'] ?? '') ? ' ('.$health['jobState'].')' : '';

        return $this->scimHealthCell(
            key: $key,
            label: $label,
            status: 'ok',
            statusLabel: 'Export active',
            whatItIsDoing: 'Entra provisioning job running'.$jobState.'. Checked within ~5m.',
            whatNext: 'New M365 users in SCIM scope become SuperOps requesters via Entra export.',
            blockers: [],
            error: null,
        );
    }

    /**
     * @param  list<string>  $blockers
     * @return array<string, mixed>
     */
    private function scimHealthCell(
        string $key,
        string $label,
        string $status,
        string $statusLabel,
        string $whatItIsDoing,
        string $whatNext,
        array $blockers,
        ?string $error,
    ): array {
        return [
            'key' => $key,
            'label' => $label,
            'friendly_label' => $this->friendlyLabel($key, $label),
            'status' => $status,
            'status_label' => $statusLabel,
            'what_it_is_doing' => $whatItIsDoing,
            'what_next' => $whatNext,
            'last_success_at' => $status === 'ok' ? now() : null,
            'age_minutes' => null,
            'sla_minutes' => null,
            'requeue_after_minutes' => null,
            'due_for_requeue' => false,
            'flag_queued' => false,
            'job_in_db' => false,
            'job_reserved' => false,
            'job_age_seconds' => null,
            'started_at' => null,
            'last_finished_at' => null,
            'duration_ms' => null,
            'detail' => $whatItIsDoing,
            'error' => $error,
            'blockers' => $blockers,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function m365Directory(Client $client, int &$clearedOrphans): array
    {
        if (! app(\App\Services\Portal\ClientProductService::class)->isEntitled($client, 'm365')) {
            return $this->disabled('m365_directory', 'M365 directory', 'Not sold');
        }

        if (! filled($client->entra_tenant_id)) {
            return $this->disabled('m365_directory', 'M365 directory', 'Setup needed');
        }

        $meta = Cache::get('m365_directory.meta.'.$client->id);
        $last = is_array($meta) && filled($meta['refreshed_at'] ?? null)
            ? Carbon::parse($meta['refreshed_at'])
            : null;
        $result = Cache::get('m365_directory.last_result.'.$client->id);
        $window = max(1, app(\App\Services\Portal\PortalFreshnessService::class)->effectiveSoftWindowMinutes());
        $requeueAfter = max(0.5, app(\App\Services\Portal\PortalFreshnessService::class)->effectiveRequeueMinutes());

        return $this->integrationStatus(
            key: 'm365_directory',
            label: 'M365 directory',
            clientId: $client->id,
            queuedKey: 'm365_directory.refresh_queued.'.$client->id,
            startedKey: 'm365_directory.refresh_started.'.$client->id,
            lastSuccessAt: $last,
            lastResult: is_array($result) ? $result : null,
            processHint: 'RefreshM365DirectoryJob (users + licences + mailbox purpose + groups)',
            requeueAfterMinutes: $requeueAfter,
            clientWindowMinutes: $window,
            clearedOrphans: $clearedOrphans,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function m365Insights(Client $client, int &$clearedOrphans): array
    {
        if (! app(\App\Services\Portal\ClientProductService::class)->isEntitled($client, 'm365')) {
            return $this->disabled('m365_insights', 'M365 licences', 'Not sold');
        }

        if (! filled($client->entra_tenant_id)) {
            return $this->disabled('m365_insights', 'M365 licences', 'Setup needed');
        }

        $payload = Cache::get(app(\App\Services\M365\M365InsightsService::class)->cacheKey($client->id));
        $last = is_array($payload) && filled($payload['last_refreshed_at'] ?? null)
            ? Carbon::parse($payload['last_refreshed_at'])
            : null;
        $result = Cache::get('m365_insights.last_result.'.$client->id);
        $window = max(1, app(\App\Services\Portal\PortalFreshnessService::class)->effectiveSoftWindowMinutes());
        $requeueAfter = max(0.5, app(\App\Services\Portal\PortalFreshnessService::class)->effectiveRequeueMinutes());

        return $this->integrationStatus(
            key: 'm365_insights',
            label: 'M365 licences',
            clientId: $client->id,
            queuedKey: 'm365_insights.refresh_queued.'.$client->id,
            startedKey: 'm365_insights.refresh_started.'.$client->id,
            lastSuccessAt: $last,
            lastResult: is_array($result) ? $result : null,
            processHint: 'RefreshM365InsightsJob (subscribedSkus)',
            requeueAfterMinutes: $requeueAfter,
            clientWindowMinutes: $window,
            clearedOrphans: $clearedOrphans,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function entraSync(Client $client, int &$clearedOrphans): array
    {
        if (! $client->entra_sync_enabled || ! filled($client->entra_tenant_id)) {
            return $this->disabled('entra_sync', 'Entra portal sync', 'Sync off or no tenant');
        }

        $last = $client->entra_synced_at;
        $result = Cache::get('entra_sync.last_result.'.$client->id);

        return $this->integrationStatus(
            key: 'entra_sync',
            label: 'Entra portal sync',
            clientId: $client->id,
            queuedKey: 'entra_sync.refresh_queued.'.$client->id,
            startedKey: 'entra_sync.refresh_started.'.$client->id,
            lastSuccessAt: $last instanceof Carbon ? $last : (filled($last) ? Carbon::parse($last) : null),
            lastResult: is_array($result) ? $result : null,
            processHint: 'SyncEntraClientJob (users → portal + group + SCIM names)',
            // portal:sync-entra-users on adaptive cadence with prewarm.
            requeueAfterMinutes: max(0.5, app(\App\Services\Portal\PortalFreshnessService::class)->effectiveRequeueMinutes()),
            clientWindowMinutes: max(1, app(\App\Services\Portal\PortalFreshnessService::class)->effectiveSoftWindowMinutes()),
            clearedOrphans: $clearedOrphans,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function huntress(Client $client, int &$clearedOrphans): array
    {
        if (! app(\App\Services\Portal\ClientProductService::class)->isEntitled($client, 'huntress')) {
            return $this->disabled('huntress', 'Huntress security', 'Not sold');
        }

        if (! (bool) config('services.huntress.enabled')) {
            return $this->disabled('huntress', 'Huntress security', 'API disabled');
        }

        if (! filled(config('services.huntress.api_key')) || ! filled(config('services.huntress.api_secret'))) {
            return $this->disabled('huntress', 'Huntress security', 'API not configured');
        }

        if (! filled($client->huntress_organization_id)) {
            return $this->disabled('huntress', 'Huntress security', 'Setup needed');
        }

        $payload = Cache::get(app(\App\Services\Huntress\HuntressClientMetricsService::class)->cacheKey($client->id));
        $last = is_array($payload) && filled($payload['last_refreshed_at'] ?? null)
            ? Carbon::parse($payload['last_refreshed_at'])
            : null;
        $result = Cache::get('huntress_security.last_result.'.$client->id);
        $requeueAfter = max(0.5, app(\App\Services\Portal\PortalFreshnessService::class)->effectiveRequeueMinutes());
        $clientWindow = max(1, app(\App\Services\Portal\PortalFreshnessService::class)->effectiveSoftWindowMinutes());

        return $this->integrationStatus(
            key: 'huntress',
            label: 'Huntress security',
            clientId: $client->id,
            queuedKey: 'huntress_security.refresh_queued.'.$client->id,
            startedKey: 'huntress_security.refresh_started.'.$client->id,
            lastSuccessAt: $last,
            lastResult: is_array($result) ? $result : null,
            processHint: 'RefreshHuntressSecurityJob (GET /v1/organizations/{id})',
            requeueAfterMinutes: $requeueAfter,
            clientWindowMinutes: $clientWindow,
            clearedOrphans: $clearedOrphans,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function dropsuite(Client $client, int &$clearedOrphans): array
    {
        if (! app(\App\Services\Portal\ClientProductService::class)->isEntitled($client, 'dropsuite')) {
            return $this->disabled('dropsuite', 'Dropsuite backups', 'Not sold');
        }

        if (! (bool) config('services.dropsuite.enabled')) {
            return $this->disabled('dropsuite', 'Dropsuite backups', 'API disabled');
        }

        if (! filled(config('services.dropsuite.reseller_token')) || ! filled(config('services.dropsuite.auth_token'))) {
            return $this->disabled('dropsuite', 'Dropsuite backups', 'API not configured');
        }

        if (! filled($client->dropsuite_organization_id)) {
            return $this->disabled('dropsuite', 'Dropsuite backups', 'Setup needed');
        }

        $payload = Cache::get(app(\App\Services\Dropsuite\DropsuiteClientMetricsService::class)->cacheKey($client->id));
        $last = is_array($payload) && filled($payload['last_refreshed_at'] ?? null)
            ? Carbon::parse($payload['last_refreshed_at'])
            : null;
        $result = Cache::get('dropsuite_backup.last_result.'.$client->id);
        $requeueAfter = max(0.5, app(\App\Services\Portal\PortalFreshnessService::class)->effectiveRequeueMinutes());
        $clientWindow = max(1, app(\App\Services\Portal\PortalFreshnessService::class)->effectiveSoftWindowMinutes());

        return $this->integrationStatus(
            key: 'dropsuite',
            label: 'Dropsuite backups',
            clientId: $client->id,
            queuedKey: 'dropsuite_backup.refresh_queued.'.$client->id,
            startedKey: 'dropsuite_backup.refresh_started.'.$client->id,
            lastSuccessAt: $last,
            lastResult: is_array($result) ? $result : null,
            processHint: 'RefreshDropsuiteBackupJob (reseller backup GET)',
            requeueAfterMinutes: $requeueAfter,
            clientWindowMinutes: $clientWindow,
            clearedOrphans: $clearedOrphans,
        );
    }

    /**
     * @param  array<string, mixed>|null  $lastResult
     * @return array<string, mixed>
     */
    private function integrationStatus(
        string $key,
        string $label,
        int $clientId,
        string $queuedKey,
        string $startedKey,
        ?Carbon $lastSuccessAt,
        ?array $lastResult,
        string $processHint,
        float|int $requeueAfterMinutes,
        float|int $clientWindowMinutes,
        int &$clearedOrphans,
    ): array {
        $flagQueued = Cache::has($queuedKey);
        $startedAtRaw = Cache::get($startedKey);
        $started = filled($startedAtRaw) ? Carbon::parse($startedAtRaw) : null;
        $job = $this->findJob($key, $clientId);
        $jobInDb = $job !== null;
        $durationMs = isset($lastResult['duration_ms']) ? (int) $lastResult['duration_ms'] : null;
        $error = is_string($lastResult['error'] ?? null) ? $lastResult['error'] : null;
        $lastFailed = is_array($lastResult) && ($lastResult['success'] ?? true) === false;
        $finishedAt = filled($lastResult['finished_at'] ?? null)
            ? Carbon::parse($lastResult['finished_at'])
            : null;

        if ($flagQueued && $started === null && ! $jobInDb) {
            Cache::forget($queuedKey);
            $flagQueued = false;
            $clearedOrphans++;
        }

        $ageExact = $lastSuccessAt?->floatDiffInMinutes(now());
        $ageRounded = $ageExact !== null ? (int) round($ageExact) : null;
        $dueForRequeue = $lastSuccessAt === null
            || ($ageExact !== null && $ageExact >= (float) $requeueAfterMinutes);
        $pastClientWindow = $ageExact !== null && $ageExact > (float) $clientWindowMinutes;

        $status = 'ok';
        $detail = $processHint;

        if ($flagQueued && $started) {
            $runningFor = (int) round($started->diffInMinutes(now()));
            $status = $runningFor >= self::STUCK_AFTER_MINUTES ? 'stuck' : 'running';
            $detail = $processHint.' · started '.$started->timezone('Europe/London')->format('H:i:s').' UK'
                .' ('.$runningFor.' min)';
        } elseif ($flagQueued) {
            $status = 'queued';
            $wait = $job !== null && isset($job['age_seconds'])
                ? ' in jobs for '.$job['age_seconds'].'s'
                : '';
            $detail = $processHint.' · waiting on queue worker'.$wait
                .($job && $job['reserved'] ? ' (reserved)' : '');
        } elseif ($lastSuccessAt === null && $lastFailed) {
            // Cold + last job failed: show Failed so Mapping ID / API errors surface in IH.
            $status = 'failed';
            $detail = $error ?: 'Last refresh failed - no successful cache yet';
        } elseif ($lastSuccessAt === null) {
            $status = 'cold';
            $detail = 'No successful refresh stored yet';
        } elseif ($lastFailed) {
            $status = 'failed';
            $detail = $error ?: 'Last refresh failed';
        } elseif ($pastClientWindow) {
            $status = 'aging';
            $detail = $processHint.' · past '.$clientWindowMinutes.'m client window ('.$ageRounded.'m ago)';
        } elseif ($dueForRequeue && in_array($key, ['superops', 'huntress', 'dropsuite', 'm365_directory', 'm365_insights'], true)) {
            // Between requeue threshold and client window - will enqueue on next prewarm.
            $status = 'due';
            $detail = $processHint.' · requeue threshold '.$requeueAfterMinutes.'m hit (age '.$ageRounded.'m)'
                .' · waiting for prewarm / workers';
        }

        $blockers = $this->buildBlockers(
            status: $status,
            label: $label,
            ageRounded: $ageRounded,
            requeueAfterMinutes: $requeueAfterMinutes,
            clientWindowMinutes: $clientWindowMinutes,
            flagQueued: $flagQueued,
            started: $started,
            job: $job,
            lastFailed: $lastFailed,
            error: $error,
            finishedAt: $finishedAt,
            dueForRequeue: $dueForRequeue,
            key: $key,
        );

        $friendly = $this->friendlyStatus(
            status: $status,
            key: $key,
            ageRounded: $ageRounded,
            requeueAfterMinutes: $requeueAfterMinutes,
            blockers: $blockers,
            job: $job,
        );

        if ($blockers !== [] && ! in_array($status, ['running', 'queued', 'stuck', 'failed', 'cold', 'disabled'], true)) {
            $detail = $friendly['what_next'];
        }

        return [
            'key' => $key,
            'label' => $label,
            'friendly_label' => $this->friendlyLabel($key, $label),
            'status' => $status,
            'status_label' => $friendly['status_label'],
            'what_it_is_doing' => $friendly['what_it_is_doing'],
            'what_next' => $friendly['what_next'],
            'last_success_at' => $lastSuccessAt,
            'age_minutes' => $ageRounded,
            'sla_minutes' => $clientWindowMinutes,
            'requeue_after_minutes' => $requeueAfterMinutes,
            'due_for_requeue' => $dueForRequeue,
            'flag_queued' => $flagQueued,
            'job_in_db' => $jobInDb,
            'job_reserved' => (bool) ($job['reserved'] ?? false),
            'job_age_seconds' => $job['age_seconds'] ?? null,
            'started_at' => $started,
            'last_finished_at' => $finishedAt,
            'duration_ms' => $durationMs,
            'detail' => $detail,
            'error' => $error,
            'blockers' => $blockers,
        ];
    }

    private function friendlyLabel(string $key, string $fallback): string
    {
        return match ($key) {
            'superops' => 'Devices & tickets',
            'superops_scim' => 'SuperOps SCIM export',
            'm365_directory' => 'M365 people list',
            'm365_insights' => 'M365 licences',
            'entra_sync' => 'Entra user sync',
            'huntress' => 'Huntress security',
            'dropsuite' => 'Dropsuite backups',
            default => $fallback,
        };
    }

    /**
     * @param  list<string>  $blockers
     * @param  array<string, mixed>|null  $job
     * @return array{status_label: string, what_it_is_doing: string, what_next: string}
     */
    private function friendlyStatus(
        string $status,
        string $key,
        ?int $ageRounded,
        float|int $requeueAfterMinutes,
        array $blockers,
        ?array $job,
    ): array {
        $ageBit = $ageRounded !== null ? "Last good data {$ageRounded}m ago." : 'No successful data yet.';

        return match ($status) {
            'ok' => [
                'status_label' => 'Up to date',
                'what_it_is_doing' => $ageBit.' Nothing running.',
                'what_next' => $key === 'entra_sync'
                    ? 'Next Entra schedule run uses adaptive interval.'
                    : "Next auto pull around {$requeueAfterMinutes}m age.",
            ],
            'due' => [
                'status_label' => 'Waiting to refresh',
                'what_it_is_doing' => $ageBit.' Not in the queue yet.',
                'what_next' => $key === 'entra_sync'
                    ? 'Runs from adaptive portal:sync-entra-users (not SuperOps prewarm).'
                    : 'Waiting for the next adaptive auto-refresh (prewarm) to queue a job.',
            ],
            'aging' => [
                'status_label' => 'Getting old',
                'what_it_is_doing' => $ageBit.' Past the freshness target.',
                'what_next' => $key === 'entra_sync'
                    ? 'Entra job is late - check schedule:run and SyncEntraClientJob workers.'
                    : 'Auto-refresh should have queued this - check prewarm + workers above.',
            ],
            'queued' => [
                'status_label' => 'In the queue',
                'what_it_is_doing' => $job
                    ? 'Job waiting '.$job['age_seconds'].'s on '.$job['queue'].' queue'
                        .(! empty($job['reserved']) ? ' (worker claimed it).' : '.')
                    : 'Marked to run; job row may still be landing.',
                'what_next' => ! empty($job['reserved'])
                    ? 'Worker is busy - wait for finish (or stuck if >5m).'
                    : 'Workers should pick this up within about a minute.',
            ],
            'running' => [
                'status_label' => 'Refreshing now',
                'what_it_is_doing' => 'Background job is running.',
                'what_next' => 'Live numbers update when this job finishes.',
            ],
            'stuck' => [
                'status_label' => 'Stuck',
                'what_it_is_doing' => 'Job has been running too long.',
                'what_next' => 'Check failed_jobs / logs; may need a worker restart or flag clear.',
            ],
            'failed' => [
                'status_label' => 'Failed',
                'what_it_is_doing' => 'Last attempt errored - showing last good data if any.',
                'what_next' => $blockers[0] ?? 'Open error detail and fix the API/config issue.',
            ],
            'cold' => [
                'status_label' => 'Never loaded',
                'what_it_is_doing' => 'No snapshot stored yet - this is incomplete for a sold/mapped product.',
                'what_next' => 'Should not sit forever: check prewarm, workers, then mapping ID / platform credentials. Force a refresh from Integration Health or the product page.',
            ],
            'disabled' => [
                'status_label' => 'Not linked',
                'what_it_is_doing' => 'This integration is not configured for the client.',
                'what_next' => 'Link SuperOps / Entra on the client if it should appear.',
            ],
            default => [
                'status_label' => strtoupper($status),
                'what_it_is_doing' => $ageBit,
                'what_next' => $blockers[0] ?? '-',
            ],
        };
    }

    /**
     * @param  array<string, mixed>|null  $job
     * @return list<string>
     */
    private function buildBlockers(
        string $status,
        string $label,
        ?int $ageRounded,
        float|int $requeueAfterMinutes,
        float|int $clientWindowMinutes,
        bool $flagQueued,
        ?Carbon $started,
        ?array $job,
        bool $lastFailed,
        ?string $error,
        ?Carbon $finishedAt,
        bool $dueForRequeue,
        string $key,
    ): array {
        $lines = [];

        if ($status === 'stuck') {
            $lines[] = "{$label}: RUNNING >".self::STUCK_AFTER_MINUTES.'m - job likely hung or worker died mid-flight';
        }

        if ($status === 'queued') {
            if ($job === null) {
                $lines[] = "{$label}: flag=queued but no jobs row - unique discard / failed dispatch";
            } elseif (! empty($job['reserved'])) {
                $lines[] = "{$label}: reserved by a worker · wait ".$job['age_seconds'].'s · attempts '.$job['attempts'];
            } else {
                $lines[] = "{$label}: in {$job['queue']} queue · waiting ".$job['age_seconds'].'s for queue:work'
                    .(($job['age_seconds'] ?? 0) >= 90 ? ' (WORKER LAG)' : '');
            }
        }

        if ($status === 'due' || ($status === 'aging' && $dueForRequeue && ! $flagQueued && $job === null)) {
            $intervalLabel = rtrim(rtrim(number_format((float) $requeueAfterMinutes / 0.9, 1), '0'), '.');
            if ($key === 'entra_sync') {
                $lines[] = "{$label}: age {$ageRounded}m · waits on adaptive portal:sync-entra-users (~{$intervalLabel}m cadence, separate from product prewarm)";
            } else {
                $lines[] = "{$label}: age {$ageRounded}m · not queued yet · next prewarm (~{$intervalLabel}m cadence) will start a job";
            }
            if ($status === 'aging') {
                $lines[] = "{$label}: past freshness target {$clientWindowMinutes}m - clients may see soft note";
            }
        }

        if ($status === 'cold') {
            $lines[] = "{$label}: no successful cache yet - prewarm should enqueue cold job";
        }

        if ($lastFailed && $error) {
            $lines[] = "{$label}: last error: {$error}"
                .($finishedAt ? ' @ '.$finishedAt->timezone('Europe/London')->format('H:i:s').' UK' : '');
        }

        if ($flagQueued && $started === null && $job === null && $status !== 'queued') {
            $lines[] = "{$label}: phantom flag cleared on this poll";
        }

        if ($key === 'superops' && $status === 'ok' && $ageRounded !== null && $ageRounded >= max(1, $requeueAfterMinutes - 2)) {
            $lines[] = "{$label}: fine for now · next requeue when age ≥{$requeueAfterMinutes}m";
        }

        return $lines;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findJob(string $integrationKey, int $clientId): ?array
    {
        if (! Schema::hasTable('jobs')) {
            return null;
        }

        $hint = self::JOB_CLASS_HINT[$integrationKey] ?? null;
        if ($hint === null) {
            return null;
        }

        $candidates = DB::table('jobs')
            ->where('payload', 'like', '%'.$hint.'%')
            ->orderBy('id')
            ->limit(80)
            ->get();

        foreach ($candidates as $row) {
            $formatted = $this->formatJobRow($row);
            if (($formatted['client_id'] ?? null) === $clientId) {
                return $formatted;
            }
        }

        return null;
    }

    /**
     * @param  list<int>|null  $accessibleClientIds  null = unscoped (super admin); otherwise only
     *                                                rows whose payload names one of these clients
     * @return list<array<string, mixed>>
     */
    private function listJobs(int $limit, ?array $accessibleClientIds = null): array
    {
        if (! Schema::hasTable('jobs')) {
            return [];
        }

        $rows = DB::table('jobs')
            ->orderBy('id')
            ->limit($accessibleClientIds === null ? $limit : self::SCOPED_QUEUE_SCAN_LIMIT)
            ->get()
            ->map(fn ($row) => $this->formatJobRow($row))
            ->values()
            ->all();
        $rows = array_slice($this->onlyAccessibleClientRows($rows, $accessibleClientIds), 0, $limit);

        $ids = collect($rows)->pluck('client_id')->filter()->unique()->values()->all();
        $names = $ids === []
            ? []
            : Client::query()->whereIn('id', $ids)->pluck('name', 'id')->all();

        return array_map(static function (array $row) use ($names): array {
            $id = $row['client_id'] ?? null;
            $row['client_name'] = ($id !== null && isset($names[$id]))
                ? (string) $names[$id]
                : null;

            return $row;
        }, $rows);
    }

    /**
     * @return array<string, mixed>
     */
    private function formatJobRow(object $row): array
    {
        $payload = (string) ($row->payload ?? '');
        $displayName = null;
        if (preg_match('/"displayName"\s*:\s*"([^"]+)"/', $payload, $m)) {
            $displayName = str_replace('\\\\', '\\', $m[1]);
        } elseif (preg_match('/(Refresh\w+Job|Sync\w+Job)/', $payload, $m)) {
            $displayName = $m[1];
        }

        $clientId = $this->extractClientIdFromJobPayload($payload);

        $created = (int) ($row->created_at ?? time());
        $reservedAt = $row->reserved_at !== null ? (int) $row->reserved_at : null;

        return [
            'id' => (int) $row->id,
            'queue' => (string) $row->queue,
            'attempts' => (int) ($row->attempts ?? 0),
            'job' => $displayName ?? 'UnknownJob',
            'client_id' => $clientId,
            'client_name' => null,
            'age_seconds' => max(0, time() - $created),
            'reserved' => $reservedAt !== null,
            'reserved_for_seconds' => $reservedAt !== null ? max(0, time() - $reservedAt) : null,
            'available_at' => (int) ($row->available_at ?? $created),
        ];
    }

    /**
     * Database queue payloads store PHP-serialized commands inside JSON and often escape quotes,
     * so a naive clientId";i:N; match fails (blank CLIENT column).
     */
    public function extractClientIdFromJobPayload(string $payload): ?int
    {
        $variants = array_values(array_filter([
            $payload,
            stripcslashes($payload),
            // JSON "command":"…" body
            preg_match('/"command"\s*:\s*"((?:\\\\.|[^"\\\\])*)"/s', $payload, $m)
                ? stripcslashes($m[1])
                : null,
        ]));

        foreach ($variants as $text) {
            if (preg_match('/clientId";i:(\d+);/', $text, $m)) {
                return (int) $m[1];
            }
            if (preg_match('/clientId\\\\";i:(\d+);/', $text, $m)) {
                return (int) $m[1];
            }
            if (preg_match('/"clientId"\s*:\s*(\d+)/', $text, $m)) {
                return (int) $m[1];
            }
            // PHP serialize with leading string length still present after partial unescape.
            if (preg_match('/s:\d+:\\\\?"clientId\\\\?";i:(\d+);/', $text, $m)) {
                return (int) $m[1];
            }
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<int>|null  $accessibleClientIds
     * @return list<array<string, mixed>>
     */
    private function onlyAccessibleClientRows(array $rows, ?array $accessibleClientIds): array
    {
        if ($accessibleClientIds === null) {
            return $rows;
        }

        $allowed = array_flip(array_map('intval', $accessibleClientIds));

        return array_values(array_filter(
            $rows,
            static fn (array $row): bool => ($row['client_id'] ?? null) !== null
                && isset($allowed[(int) $row['client_id']]),
        ));
    }

    /**
     * @param  list<int>|null  $accessibleClientIds  see listJobs()
     * @return list<array<string, mixed>>
     */
    private function recentFailures(int $limit, ?array $accessibleClientIds = null): array
    {
        if (! Schema::hasTable('failed_jobs')) {
            return [];
        }

        $rows = DB::table('failed_jobs')
            ->orderByDesc('id')
            ->limit($accessibleClientIds === null ? $limit : self::SCOPED_QUEUE_SCAN_LIMIT)
            ->get()
            ->map(function ($row): array {
                $payload = (string) ($row->payload ?? '');
                $job = 'UnknownJob';
                if (preg_match('/"displayName"\s*:\s*"([^"]+)"/', $payload, $m)) {
                    $job = str_replace('\\\\', '\\', $m[1]);
                } elseif (preg_match('/(Refresh\w+Job|Sync\w+Job)/', $payload, $m)) {
                    $job = $m[1];
                }

                $clientId = $this->extractClientIdFromJobPayload($payload);

                $exception = (string) ($row->exception ?? '');
                $firstLine = trim(strtok($exception, "\n") ?: $exception);

                return [
                    'id' => (int) $row->id,
                    'queue' => (string) ($row->queue ?? 'default'),
                    'job' => class_basename($job),
                    'client_id' => $clientId,
                    'failed_at' => filled($row->failed_at ?? null) ? Carbon::parse($row->failed_at) : null,
                    'error' => \Illuminate\Support\Str::limit($firstLine, 160),
                ];
            })
            ->all();

        return array_slice($this->onlyAccessibleClientRows($rows, $accessibleClientIds), 0, $limit);
    }

    /**
     * @return array<string, mixed>
     */
    private function disabled(string $key, string $label, string $reason): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'status' => 'disabled',
            'last_success_at' => null,
            'age_minutes' => null,
            'sla_minutes' => null,
            'requeue_after_minutes' => null,
            'due_for_requeue' => false,
            'flag_queued' => false,
            'job_in_db' => false,
            'job_reserved' => false,
            'job_age_seconds' => null,
            'started_at' => null,
            'last_finished_at' => null,
            'duration_ms' => null,
            'detail' => $reason,
            'error' => null,
            'blockers' => [],
            'friendly_label' => $this->friendlyLabel($key, $label),
            'status_label' => 'Not linked',
            'what_it_is_doing' => $reason,
            'what_next' => 'Configure on the client record if this should show data.',
        ];
    }
}
