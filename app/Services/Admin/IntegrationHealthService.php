<?php

namespace App\Services\Admin;

use App\Models\Client;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Technician-only view of per-client background refresh health.
 * Not shown to client_admin / end customers.
 */
class IntegrationHealthService
{
    /** Consider a queued flag "stuck" after this many minutes. */
    public const STUCK_AFTER_MINUTES = 5;

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
     *     queue: array{pending: int, failed: int, high: int, default: int, oldest_pending_seconds: ?int},
     *     clients: list<array<string, mixed>>,
     *     stuck_count: int,
     * }
     */
    public function overview(?array $accessibleClientIds = null): array
    {
        $clients = Client::query()
            ->where('is_active', true)
            ->when(
                $accessibleClientIds !== null && $accessibleClientIds !== [],
                fn ($q) => $q->whereIn('id', $accessibleClientIds),
            )
            ->orderBy('name')
            ->get();

        $rows = $clients->map(fn (Client $client): array => $this->clientRow($client))->values()->all();
        $stuckCount = collect($rows)->where('is_stuck', true)->count();

        return [
            'queue' => $this->queueSummary(),
            'clients' => $rows,
            'stuck_count' => $stuckCount,
        ];
    }

    /**
     * @return array{pending: int, failed: int, high: int, default: int, oldest_pending_seconds: ?int}
     */
    public function queueSummary(): array
    {
        if (! Schema::hasTable('jobs')) {
            return [
                'pending' => 0,
                'failed' => 0,
                'high' => 0,
                'default' => 0,
                'oldest_pending_seconds' => null,
            ];
        }

        $pending = (int) DB::table('jobs')->count();
        $high = (int) DB::table('jobs')->where('queue', 'high')->count();
        $default = (int) DB::table('jobs')->where('queue', 'default')->count();
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
            'oldest_pending_seconds' => $oldestSeconds,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function clientRow(Client $client): array
    {
        $integrations = [
            $this->superOps($client),
            $this->m365Directory($client),
            $this->m365Insights($client),
            $this->entraSync($client),
        ];

        $active = collect($integrations)->first(
            fn (array $row): bool => in_array($row['status'] ?? '', ['running', 'queued', 'stuck'], true),
        );

        $stuck = collect($integrations)->contains(
            fn (array $row): bool => ($row['status'] ?? '') === 'stuck',
        );

        $worstAgeMinutes = collect($integrations)
            ->pluck('age_minutes')
            ->filter(fn ($v) => $v !== null)
            ->max();

        return [
            'client_id' => $client->id,
            'client_name' => $client->name,
            'is_stuck' => $stuck,
            'active_process' => $active['label'] ?? null,
            'active_status' => $active['status'] ?? null,
            'active_detail' => $active['detail'] ?? null,
            'worst_age_minutes' => $worstAgeMinutes,
            'integrations' => $integrations,
        ];
    }

    /**
     * @return array{key: string, label: string, status: string, last_success_at: ?Carbon, age_minutes: ?int, duration_ms: ?int, detail: ?string, error: ?string}
     */
    private function superOps(Client $client): array
    {
        if (! filled($client->superops_account_id)) {
            return $this->disabled('superops', 'SuperOps dashboard', 'Not linked');
        }

        $payload = Cache::get("client:{$client->id}:superops-dashboard:v2");
        $last = is_array($payload) && filled($payload['last_refreshed_at'] ?? null)
            ? Carbon::parse($payload['last_refreshed_at'])
            : null;
        $result = Cache::get('superops_dashboard.last_result.'.$client->id);

        return $this->integrationStatus(
            key: 'superops',
            label: 'SuperOps dashboard',
            clientId: $client->id,
            queuedKey: 'superops_dashboard.refresh_queued.'.$client->id,
            startedKey: 'superops_dashboard.refresh_started.'.$client->id,
            lastSuccessAt: $last,
            lastResult: is_array($result) ? $result : null,
            processHint: 'RefreshSuperOpsDashboardJob (GraphQL tickets + assets)',
        );
    }

    /**
     * @return array{key: string, label: string, status: string, last_success_at: ?Carbon, age_minutes: ?int, duration_ms: ?int, detail: ?string, error: ?string}
     */
    private function m365Directory(Client $client): array
    {
        if (! filled($client->entra_tenant_id)) {
            return $this->disabled('m365_directory', 'M365 directory', 'No Entra tenant');
        }

        $meta = Cache::get('m365_directory.meta.'.$client->id);
        $last = is_array($meta) && filled($meta['refreshed_at'] ?? null)
            ? Carbon::parse($meta['refreshed_at'])
            : null;
        $result = Cache::get('m365_directory.last_result.'.$client->id);

        return $this->integrationStatus(
            key: 'm365_directory',
            label: 'M365 directory',
            clientId: $client->id,
            queuedKey: 'm365_directory.refresh_queued.'.$client->id,
            startedKey: 'm365_directory.refresh_started.'.$client->id,
            lastSuccessAt: $last,
            lastResult: is_array($result) ? $result : null,
            processHint: 'RefreshM365DirectoryJob (users + licences + mailbox purpose + groups)',
        );
    }

    /**
     * @return array{key: string, label: string, status: string, last_success_at: ?Carbon, age_minutes: ?int, duration_ms: ?int, detail: ?string, error: ?string}
     */
    private function m365Insights(Client $client): array
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

        return $this->integrationStatus(
            key: 'm365_insights',
            label: 'M365 licences',
            clientId: $client->id,
            queuedKey: 'm365_insights.refresh_queued.'.$client->id,
            startedKey: 'm365_insights.refresh_started.'.$client->id,
            lastSuccessAt: $last,
            lastResult: is_array($result) ? $result : null,
            processHint: 'RefreshM365InsightsJob (subscribedSkus)',
        );
    }

    /**
     * @return array{key: string, label: string, status: string, last_success_at: ?Carbon, age_minutes: ?int, duration_ms: ?int, detail: ?string, error: ?string}
     */
    private function entraSync(Client $client): array
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
        );
    }

    /**
     * @param  array<string, mixed>|null  $lastResult
     * @return array{key: string, label: string, status: string, last_success_at: ?Carbon, age_minutes: ?int, duration_ms: ?int, detail: ?string, error: ?string}
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
    ): array {
        $queued = Cache::has($queuedKey);
        $startedAt = Cache::get($startedKey);
        $started = filled($startedAt) ? Carbon::parse($startedAt) : null;
        $durationMs = isset($lastResult['duration_ms']) ? (int) $lastResult['duration_ms'] : null;
        $error = is_string($lastResult['error'] ?? null) ? $lastResult['error'] : null;

        // Orphaned "queued" cache with nothing in jobs = phantom status (unique discard, killed worker, etc.)
        if ($queued && $started === null && ! $this->jobPendingInDatabase($key, $clientId)) {
            Cache::forget($queuedKey);
            $queued = false;
        }

        $status = 'ok';
        $detail = $processHint;

        if ($queued && $started) {
            $runningFor = $started->diffInMinutes(now());
            $status = $runningFor >= self::STUCK_AFTER_MINUTES ? 'stuck' : 'running';
            $detail = $processHint.' · started '.$started->timezone('Europe/London')->format('H:i:s').' UK'
                .' ('.$runningFor.' min)';
        } elseif ($queued) {
            $status = 'queued';
            $detail = $processHint.' · waiting on queue worker';
        } elseif ($lastSuccessAt === null) {
            $status = 'cold';
            $detail = 'No successful refresh stored yet';
        } elseif ($error && ($lastResult['success'] ?? true) === false) {
            $status = 'failed';
            $detail = $error;
        }

        $ageMinutes = $lastSuccessAt?->diffInMinutes(now());

        return [
            'key' => $key,
            'label' => $label,
            'status' => $status,
            'last_success_at' => $lastSuccessAt,
            'age_minutes' => $ageMinutes !== null ? (int) round($ageMinutes) : null,
            'duration_ms' => $durationMs,
            'detail' => $detail,
            'error' => $error,
        ];
    }

    /**
     * True if a matching job row still exists (pending or reserved).
     */
    private function jobPendingInDatabase(string $integrationKey, int $clientId): bool
    {
        if (! Schema::hasTable('jobs')) {
            return false;
        }

        $hint = self::JOB_CLASS_HINT[$integrationKey] ?? null;
        if ($hint === null) {
            return false;
        }

        // Serialized job payload includes class name + public int $clientId.
        return DB::table('jobs')
            ->where('payload', 'like', '%'.$hint.'%')
            ->where('payload', 'like', '%clientId";i:'.$clientId.';%')
            ->exists();
    }

    /**
     * @return array{key: string, label: string, status: string, last_success_at: ?Carbon, age_minutes: ?int, duration_ms: ?int, detail: ?string, error: ?string}
     */
    private function disabled(string $key, string $label, string $reason): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'status' => 'disabled',
            'last_success_at' => null,
            'age_minutes' => null,
            'duration_ms' => null,
            'detail' => $reason,
            'error' => null,
        ];
    }
}
