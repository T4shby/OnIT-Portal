<?php

namespace App\Services\Admin;

use App\Models\Client;
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
    ];

    /**
     * @param  list<int>|null  $accessibleClientIds  empty = all clients (super admin)
     * @return array{
     *     queue: array<string, mixed>,
     *     pipeline: array<string, mixed>,
     *     notices: list<string>,
     *     clients: list<array<string, mixed>>,
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
                $accessibleClientIds !== null && $accessibleClientIds !== [],
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

        $queue = $this->queueSummary();
        $pipeline = $this->pipelineSummary($queue);
        $notices = $this->buildNotices($pipeline, $rows, $clearedOrphans, $stuckCount, $agingCount, $dueCount);

        return [
            'queue' => $queue,
            'pipeline' => $pipeline,
            'notices' => $notices,
            'clients' => $rows,
            'stuck_count' => $stuckCount,
            'aging_count' => $agingCount,
            'due_count' => $dueCount,
            'cleared_orphans' => $clearedOrphans,
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
    public function queueSummary(): array
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
            'jobs' => $this->listJobs(20),
            'recent_failures' => $this->recentFailures(8),
        ];
    }

    /**
     * @param  array<string, mixed>  $queue
     * @return array<string, mixed>
     */
    private function pipelineSummary(array $queue): array
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

        $superOpsRequeueAfter = max(1, (int) config('services.superops.dashboard_refresh_after_minutes', 10));
        $superOpsClientWindow = max(1, (int) config('services.superops.dashboard_cache_minutes', 15));

        $workerLagSuspect = ($queue['pending'] ?? 0) > 0
            && ($queue['reserved'] ?? 0) === 0
            && ($queue['oldest_pending_seconds'] ?? 0) >= 90;

        $scheduleLocks = $this->scheduleLockRows();
        $stuckScheduleLocks = collect($scheduleLocks)
            ->filter(fn (array $lock): bool => ($lock['held_for_seconds'] ?? 0) > 300)
            ->values()
            ->all();

        $schedulerOk = $tickAt !== null && ($tickAgeMinutes ?? 99) <= 2;
        $prewarmOk = $prewarmAt !== null && ($prewarmAgeMinutes ?? 99) <= 7;

        $headline = 'All systems refreshing normally';
        $severityLevel = 'ok';
        if (! $schedulerOk) {
            $headline = 'Minute scheduler is not ticking — cron schedule:run may be dead';
            $severityLevel = 'critical';
        } elseif ($stuckScheduleLocks !== []) {
            $headline = 'A schedule lock is stuck — auto-refresh cannot start until it is cleared';
            $severityLevel = 'critical';
        } elseif (! $prewarmOk) {
            $headline = 'Auto-refresh (prewarm) is late — client data will age until it runs again';
            $severityLevel = 'warning';
        } elseif ($workerLagSuspect) {
            $headline = 'Jobs are waiting but no worker is processing them';
            $severityLevel = 'critical';
        } elseif (($queue['pending'] ?? 0) > 0) {
            $headline = 'Refresh jobs are in the queue and should finish shortly';
            $severityLevel = 'info';
        }

        return [
            'generated_at' => now(),
            'headline' => $headline,
            'severity_level' => $severityLevel,
            'superops_requeue_after_minutes' => $superOpsRequeueAfter,
            'superops_client_window_minutes' => $superOpsClientWindow,
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
                'clients' => is_array($prewarm) ? (int) ($prewarm['clients'] ?? 0) : null,
                'queue_deep' => is_array($prewarm) ? (bool) ($prewarm['queue_deep'] ?? false) : null,
                'pending_before' => is_array($prewarm) ? (int) ($prewarm['pending_before'] ?? 0) : null,
                'interval_minutes' => 5,
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
     * @return list<array{key: string, age_seconds: int, held_for_seconds: int, expiration: int}>
     */
    private function scheduleLockRows(): array
    {
        if (! Schema::hasTable('cache_locks')) {
            return [];
        }

        $now = time();

        return DB::table('cache_locks')
            ->orderBy('key')
            ->get()
            ->map(function ($row) use ($now): array {
                $exp = (int) ($row->expiration ?? 0);

                return [
                    'key' => (string) $row->key,
                    'expiration' => $exp,
                    'age_seconds' => max(0, $exp > $now ? 0 : ($now - $exp)),
                    // Approximate held time only if we know expiry was set far ahead; use remaining as signal.
                    'held_for_seconds' => $exp > $now ? max(0, (int) ($exp - $now)) : max(0, $now - $exp),
                    'seconds_until_release' => max(0, $exp - $now),
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
            $notices[] = "{$n} schedule lock(s) held in cache_locks — auto tasks can wait forever. Cleared on next prewarm start if still stuck after deploy.";
        }

        if ($pipeline['prewarm']['never_ran'] ?? false) {
            $notices[] = 'Prewarm has never recorded a run. Auto client metrics will stay empty/old.';
        } elseif ($pipeline['prewarm']['overdue'] ?? false) {
            $age = $pipeline['prewarm']['age_minutes'] ?? '?';
            $notices[] = "Prewarm last ran {$age}m ago (expect every 5m). Until it runs, aging data will not auto-reset.";
        }

        if ($pipeline['workers']['lag_suspect'] ?? false) {
            $oldest = $pipeline['workers']['oldest_pending_seconds'] ?? 0;
            $pending = $pipeline['workers']['pending'] ?? 0;
            $notices[] = "{$pending} job(s) waiting with nothing reserved — workers not draining. Oldest ~".(int) round($oldest / 60).'m.';
        }

        if ($clearedOrphans > 0) {
            $notices[] = "Cleared {$clearedOrphans} orphaned 'queued' flag(s) (said queued but no job row).";
        }

        if ($stuckCount > 0) {
            $notices[] = "{$stuckCount} refresh job(s) stuck (running over ".self::STUCK_AFTER_MINUTES.' minutes).';
        }

        if ($agingCount > 0) {
            $notices[] = "{$agingCount} data source(s) past freshness — clients may see softer wording.";
        }

        if ($dueCount > 0) {
            $requeue = $pipeline['superops_requeue_after_minutes'] ?? 10;
            $notices[] = "{$dueCount} SuperOps feed(s) due for refresh (older than {$requeue}m) but not started yet.";
        }

        foreach ($rows as $row) {
            foreach ($row['integrations'] as $cell) {
                if (($cell['status'] ?? '') === 'failed' && filled($cell['error'] ?? null)) {
                    $notices[] = "{$row['client_name']}: {$cell['friendly_label']} failed — {$cell['error']}";
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
        $integrations = [
            $this->superOps($client, $clearedOrphans),
            $this->m365Directory($client, $clearedOrphans),
            $this->m365Insights($client, $clearedOrphans),
            $this->entraSync($client, $clearedOrphans),
        ];

        $active = collect($integrations)->first(
            fn (array $row): bool => in_array($row['status'] ?? '', ['running', 'queued', 'stuck'], true),
        );

        $stuck = collect($integrations)->contains(
            fn (array $row): bool => ($row['status'] ?? '') === 'stuck',
        );

        $agingCount = collect($integrations)->where('status', 'aging')->count();
        $dueCount = collect($integrations)->where('status', 'due')->count();

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
            'blockers' => $blockers,
            'integrations' => $integrations,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function superOps(Client $client, int &$clearedOrphans): array
    {
        if (! filled($client->superops_account_id)) {
            return $this->disabled('superops', 'SuperOps dashboard', 'Not linked');
        }

        $payload = Cache::get("client:{$client->id}:superops-dashboard:v2");
        $last = is_array($payload) && filled($payload['last_refreshed_at'] ?? null)
            ? Carbon::parse($payload['last_refreshed_at'])
            : null;
        $result = Cache::get('superops_dashboard.last_result.'.$client->id);
        $requeueAfter = max(1, (int) config('services.superops.dashboard_refresh_after_minutes', 10));
        $clientWindow = max(1, (int) config('services.superops.dashboard_cache_minutes', 15));

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
     * @return array<string, mixed>
     */
    private function m365Directory(Client $client, int &$clearedOrphans): array
    {
        if (! filled($client->entra_tenant_id)) {
            return $this->disabled('m365_directory', 'M365 directory', 'No Entra tenant');
        }

        $meta = Cache::get('m365_directory.meta.'.$client->id);
        $last = is_array($meta) && filled($meta['refreshed_at'] ?? null)
            ? Carbon::parse($meta['refreshed_at'])
            : null;
        $result = Cache::get('m365_directory.last_result.'.$client->id);
        $window = max(1, (int) config('services.entra_sync.directory_cache_minutes', 15));

        return $this->integrationStatus(
            key: 'm365_directory',
            label: 'M365 directory',
            clientId: $client->id,
            queuedKey: 'm365_directory.refresh_queued.'.$client->id,
            startedKey: 'm365_directory.refresh_started.'.$client->id,
            lastSuccessAt: $last,
            lastResult: is_array($result) ? $result : null,
            processHint: 'RefreshM365DirectoryJob (users + licences + mailbox purpose + groups)',
            requeueAfterMinutes: $window,
            clientWindowMinutes: $window,
            clearedOrphans: $clearedOrphans,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function m365Insights(Client $client, int &$clearedOrphans): array
    {
        if (! filled($client->entra_tenant_id)) {
            return $this->disabled('m365_insights', 'M365 licences', 'No Entra tenant');
        }

        $payload = Cache::get("client:{$client->id}:m365-insights:v3")
            ?? Cache::get("client:{$client->id}:m365-insights:v2")
            ?? Cache::get("client:{$client->id}:m365-insights:v1");
        $last = is_array($payload) && filled($payload['last_refreshed_at'] ?? null)
            ? Carbon::parse($payload['last_refreshed_at'])
            : null;
        $result = Cache::get('m365_insights.last_result.'.$client->id);
        $window = max(1, (int) config('services.m365_insights.insights_cache_minutes', 15));

        return $this->integrationStatus(
            key: 'm365_insights',
            label: 'M365 licences',
            clientId: $client->id,
            queuedKey: 'm365_insights.refresh_queued.'.$client->id,
            startedKey: 'm365_insights.refresh_started.'.$client->id,
            lastSuccessAt: $last,
            lastResult: is_array($result) ? $result : null,
            processHint: 'RefreshM365InsightsJob (subscribedSkus)',
            requeueAfterMinutes: $window,
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
            requeueAfterMinutes: 90,
            clientWindowMinutes: 90,
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
        int $requeueAfterMinutes,
        int $clientWindowMinutes,
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

        $ageMinutes = $lastSuccessAt?->diffInMinutes(now());
        $ageRounded = $ageMinutes !== null ? (int) round($ageMinutes) : null;
        $dueForRequeue = $lastSuccessAt === null
            || ($ageRounded !== null && $ageRounded >= $requeueAfterMinutes);
        $pastClientWindow = $ageRounded !== null && $ageRounded > $clientWindowMinutes;

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
        } elseif ($lastSuccessAt === null) {
            $status = 'cold';
            $detail = 'No successful refresh stored yet';
        } elseif ($lastFailed) {
            $status = 'failed';
            $detail = $error ?: 'Last refresh failed';
        } elseif ($pastClientWindow) {
            $status = 'aging';
            $detail = $processHint.' · past '.$clientWindowMinutes.'m client window ('.$ageRounded.'m ago)';
        } elseif ($dueForRequeue && $key === 'superops') {
            // Between requeue threshold and client window — will enqueue on next prewarm.
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
            'm365_directory' => 'M365 people list',
            'm365_insights' => 'M365 licences',
            'entra_sync' => 'Entra user sync',
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
        int $requeueAfterMinutes,
        array $blockers,
        ?array $job,
    ): array {
        $ageBit = $ageRounded !== null ? "Last good data {$ageRounded}m ago." : 'No successful data yet.';

        return match ($status) {
            'ok' => [
                'status_label' => 'Up to date',
                'what_it_is_doing' => $ageBit.' Nothing running.',
                'what_next' => $key === 'superops'
                    ? "Next auto pull around {$requeueAfterMinutes}m age."
                    : ($key === 'entra_sync' ? 'Hourly Entra sync when due.' : 'Next prewarm refreshes if older than target.'),
            ],
            'due' => [
                'status_label' => 'Waiting to refresh',
                'what_it_is_doing' => $ageBit.' Not in the queue yet.',
                'what_next' => $key === 'entra_sync'
                    ? 'Runs from hourly portal:sync-entra-users (not the 5m prewarm).'
                    : 'Waiting for the 5-minute auto-refresh (prewarm) to queue a job.',
            ],
            'aging' => [
                'status_label' => 'Getting old',
                'what_it_is_doing' => $ageBit.' Past the freshness target.',
                'what_next' => $key === 'entra_sync'
                    ? 'Hourly Entra job is late — check schedule:run and SyncEntraClientJob workers.'
                    : 'Auto-refresh should have queued this — check prewarm + workers above.',
            ],
            'queued' => [
                'status_label' => 'In the queue',
                'what_it_is_doing' => $job
                    ? 'Job waiting '.$job['age_seconds'].'s on '.$job['queue'].' queue'
                        .(! empty($job['reserved']) ? ' (worker claimed it).' : '.')
                    : 'Marked to run; job row may still be landing.',
                'what_next' => ! empty($job['reserved'])
                    ? 'Worker is busy — wait for finish (or stuck if >5m).'
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
                'what_it_is_doing' => 'Last attempt errored — showing last good data if any.',
                'what_next' => $blockers[0] ?? 'Open error detail and fix the API/config issue.',
            ],
            'cold' => [
                'status_label' => 'Never loaded',
                'what_it_is_doing' => 'No snapshot stored yet.',
                'what_next' => 'Prewarm should queue a first pull automatically.',
            ],
            'disabled' => [
                'status_label' => 'Not linked',
                'what_it_is_doing' => 'This integration is not configured for the client.',
                'what_next' => 'Link SuperOps / Entra on the client if it should appear.',
            ],
            default => [
                'status_label' => strtoupper($status),
                'what_it_is_doing' => $ageBit,
                'what_next' => $blockers[0] ?? '—',
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
        int $requeueAfterMinutes,
        int $clientWindowMinutes,
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
            $lines[] = "{$label}: RUNNING >".self::STUCK_AFTER_MINUTES.'m — job likely hung or worker died mid-flight';
        }

        if ($status === 'queued') {
            if ($job === null) {
                $lines[] = "{$label}: flag=queued but no jobs row — unique discard / failed dispatch";
            } elseif (! empty($job['reserved'])) {
                $lines[] = "{$label}: reserved by a worker · wait ".$job['age_seconds'].'s · attempts '.$job['attempts'];
            } else {
                $lines[] = "{$label}: in {$job['queue']} queue · waiting ".$job['age_seconds'].'s for queue:work'
                    .(($job['age_seconds'] ?? 0) >= 90 ? ' (WORKER LAG)' : '');
            }
        }

        if ($status === 'due' || ($status === 'aging' && $dueForRequeue && ! $flagQueued && $job === null)) {
            if ($key === 'entra_sync') {
                $lines[] = "{$label}: age {$ageRounded}m · waits on hourly portal:sync-entra-users (not 5m prewarm)";
            } else {
                $lines[] = "{$label}: age {$ageRounded}m · not queued · waiting for 5m prewarm to start a job";
            }
            if ($status === 'aging') {
                $lines[] = "{$label}: past freshness target {$clientWindowMinutes}m — clients may see soft note";
            }
        }

        if ($status === 'cold') {
            $lines[] = "{$label}: no successful cache yet — prewarm should enqueue cold job";
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

        $row = DB::table('jobs')
            ->where('payload', 'like', '%'.$hint.'%')
            ->where('payload', 'like', '%clientId";i:'.$clientId.';%')
            ->orderBy('id')
            ->first();

        if ($row === null) {
            return null;
        }

        return $this->formatJobRow($row);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function listJobs(int $limit): array
    {
        if (! Schema::hasTable('jobs')) {
            return [];
        }

        return DB::table('jobs')
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => $this->formatJobRow($row))
            ->all();
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

        $clientId = null;
        if (preg_match('/clientId";i:(\d+);/', $payload, $m)) {
            $clientId = (int) $m[1];
        }

        $created = (int) ($row->created_at ?? time());
        $reservedAt = $row->reserved_at !== null ? (int) $row->reserved_at : null;

        return [
            'id' => (int) $row->id,
            'queue' => (string) $row->queue,
            'attempts' => (int) ($row->attempts ?? 0),
            'job' => $displayName ?? 'UnknownJob',
            'client_id' => $clientId,
            'age_seconds' => max(0, time() - $created),
            'reserved' => $reservedAt !== null,
            'reserved_for_seconds' => $reservedAt !== null ? max(0, time() - $reservedAt) : null,
            'available_at' => (int) ($row->available_at ?? $created),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function recentFailures(int $limit): array
    {
        if (! Schema::hasTable('failed_jobs')) {
            return [];
        }

        return DB::table('failed_jobs')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(function ($row): array {
                $payload = (string) ($row->payload ?? '');
                $job = 'UnknownJob';
                if (preg_match('/"displayName"\s*:\s*"([^"]+)"/', $payload, $m)) {
                    $job = str_replace('\\\\', '\\', $m[1]);
                } elseif (preg_match('/(Refresh\w+Job|Sync\w+Job)/', $payload, $m)) {
                    $job = $m[1];
                }

                $clientId = null;
                if (preg_match('/clientId";i:(\d+);/', $payload, $m)) {
                    $clientId = (int) $m[1];
                }

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
