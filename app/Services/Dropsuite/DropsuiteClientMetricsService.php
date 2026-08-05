<?php

namespace App\Services\Dropsuite;

use App\Jobs\RefreshDropsuiteBackupJob;
use App\Models\Client;
use App\Services\Portal\ClearsOrphanedFeedRefreshFlags;
use App\Services\Portal\PortalFreshnessService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Client Admin Dropsuite / NinjaOne SaaS Backup metrics.
 *
 * @see App\Contracts\DashboardFeed (DropsuiteDashboardFeed adapter)
 */
class DropsuiteClientMetricsService
{
    use ClearsOrphanedFeedRefreshFlags;

    private const STALE_RETENTION_MINUTES = 1440;

    public function __construct(private DropsuiteApiClient $api) {}

    public function isAvailable(): bool
    {
        return $this->api->isConfigured();
    }

    public function summaryForClient(Client $client, bool $manualRefresh = false): DropsuiteClientBackupSummary
    {
        if (! $this->isAvailable()) {
            return $this->unavailableSummary('Dropsuite API is not configured.');
        }

        if (empty($client->dropsuite_organization_id)) {
            return $this->unavailableSummary('Dropsuite is not connected for this organisation.');
        }

        $cacheKey = $this->cacheKey($client->id);
        $cached = Cache::get($cacheKey);

        if (is_array($cached) && ! $manualRefresh) {
            return $this->summaryFromCache($client->id, $cached);
        }

        if ($manualRefresh) {
            $this->queueRefresh($client);
            $cached = Cache::get($cacheKey);
            if (is_array($cached)) {
                return $this->summaryFromCache($client->id, $cached, refreshInProgress: true);
            }
        } else {
            $this->queueRefresh($client);
        }

        if (is_array($cached)) {
            return $this->summaryFromCache($client->id, $cached, refreshInProgress: true);
        }

        return new DropsuiteClientBackupSummary(
            protectedMailboxes: null,
            lastBackupStatus: 'unknown',
            failedBackupsCount: null,
            available: false,
            unavailableReason: 'Data has not been synchronised yet.',
            lastRefreshedAt: null,
            isStale: true,
            refreshInProgress: true,
        );
    }

    public function queueRefresh(Client $client, bool $respectCooldown = false): bool
    {
        if (empty($client->dropsuite_organization_id) || ! $this->isAvailable()) {
            return false;
        }

        $this->clearOrphanedFeedFlags($client->id, 'dropsuite_backup', 'RefreshDropsuiteBackupJob');

        if ($respectCooldown) {
            $cooldownKey = 'dropsuite_backup.refresh_cooldown.'.$client->id;
            if (Cache::has($cooldownKey)) {
                return false;
            }
            Cache::put($cooldownKey, true, now()->addSeconds(60));
        }

        if (Cache::has('dropsuite_backup.refresh_queued.'.$client->id)) {
            return false;
        }

        Cache::put('dropsuite_backup.refresh_queued.'.$client->id, true, now()->addMinutes(5));
        RefreshDropsuiteBackupJob::dispatch($client->id);

        return true;
    }

    public function needsBackgroundRefresh(Client $client): bool
    {
        if (empty($client->dropsuite_organization_id) || ! $this->isAvailable()) {
            return false;
        }

        $this->clearOrphanedFeedFlags($client->id, 'dropsuite_backup', 'RefreshDropsuiteBackupJob');

        if (Cache::has('dropsuite_backup.refresh_queued.'.$client->id)) {
            return false;
        }

        $cached = Cache::get($this->cacheKey($client->id));
        if (! is_array($cached) || ! filled($cached['last_refreshed_at'] ?? null)) {
            return true;
        }

        $after = max(0.5, app(PortalFreshnessService::class)->effectiveRequeueMinutes());
        $seconds = max(30, (int) round($after * 60) - 20);

        return Carbon::parse($cached['last_refreshed_at'])->lte(now()->subSeconds($seconds));
    }

    public function refreshAndStore(Client $client): DropsuiteClientBackupSummary
    {
        $organizationId = (string) $client->dropsuite_organization_id;
        $lock = Cache::lock('dropsuite_backup.refresh.'.$client->id, 300);

        if (! $lock->get()) {
            $cached = Cache::get($this->cacheKey($client->id));
            if (is_array($cached)) {
                return $this->summaryFromCache($client->id, $cached, refreshInProgress: true);
            }

            return $this->unavailableSummary('Dropsuite backup refresh is already in progress.');
        }

        try {
            $payload = $this->fetchOrganizationBackupSummary($organizationId);
            $payload['last_refreshed_at'] = now()->toIso8601String();

            Cache::put(
                $this->cacheKey($client->id),
                $payload,
                now()->addMinutes(self::STALE_RETENTION_MINUTES),
            );

            return $this->summaryFromCache($client->id, $payload);
        } catch (Throwable $e) {
            Log::warning('Dropsuite backup refresh failed', [
                'client_id' => $client->id,
                'organization_id' => $organizationId,
                'error' => $e->getMessage(),
            ]);

            $cached = Cache::get($this->cacheKey($client->id));
            if (is_array($cached)) {
                return $this->summaryFromCache($client->id, $cached, isStale: true);
            }

            return $this->unavailableSummary('Dropsuite backup data is currently unavailable.');
        } finally {
            Cache::forget('dropsuite_backup.refresh_queued.'.$client->id);
            $lock->release();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchOrganizationBackupSummary(string $organizationId): array
    {
        $id = trim($organizationId, '/');

        // Order: common partner GET shapes (Browsable API differs by site).
        $candidates = [
            "accounts/{$id}/",
            "accounts/{$id}",
            "organizations/{$id}/",
            "organizations/{$id}",
            "organizations/{$id}/backup-summary/",
            "organizations/{$id}/backup-summary",
        ];

        $lastError = null;

        foreach ($candidates as $path) {
            try {
                $payload = $this->api->get($path);
                $mapped = $this->mapPayload($payload, $id);
                if ($this->mappedHasSignal($mapped)) {
                    $mapped['source_path'] = $path;

                    return $mapped;
                }
            } catch (Throwable $e) {
                $lastError = $e;
            }
        }

        // Reseller list endpoints — filter by id when org detail paths failed.
        foreach (['accounts/', 'organizations/', 'mailboxes/'] as $listPath) {
            try {
                $list = $this->api->get($listPath);
                $mapped = $this->mapFromList($list, $id);
                if ($mapped !== null && $this->mappedHasSignal($mapped)) {
                    $mapped['source_path'] = $listPath;

                    return $mapped;
                }
            } catch (Throwable $e) {
                $lastError = $e;
            }
        }

        if ($lastError !== null) {
            throw $lastError;
        }

        throw new \RuntimeException('Dropsuite API returned no mappable backup summary for '.$organizationId);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function mapPayload(array $payload, ?string $organizationId = null): array
    {
        $summary = $payload['data'] ?? $payload['results'] ?? $payload['account'] ?? $payload['organization'] ?? $payload;

        if (is_array($summary) && array_is_list($summary) && $organizationId !== null) {
            foreach ($summary as $row) {
                if (! is_array($row)) {
                    continue;
                }
                if ($this->rowMatchesOrg($row, $organizationId)) {
                    $summary = $row;
                    break;
                }
            }
        }

        if (! is_array($summary)) {
            return [
                'protected_mailboxes' => null,
                'last_backup_status' => 'unknown',
                'failed_backups_count' => null,
            ];
        }

        $protectedMailboxes = $this->firstInt($summary, [
            'protectedMailboxes',
            'protected_mailboxes',
            'protected_mailboxes_count',
            'mailboxes_protected',
            'total_protected_mailboxes',
            'mailbox_count',
            'protected_users',
            'users_count',
            'seats',
            'total_users',
        ]);

        if ($protectedMailboxes === null && is_array($summary['mailboxes'] ?? null)) {
            $mailboxes = $summary['mailboxes'];
            if (array_is_list($mailboxes)) {
                $protectedMailboxes = count($mailboxes);
            } else {
                $protectedMailboxes = $this->firstInt($mailboxes, ['protected', 'protected_count', 'total', 'count']);
            }
        }

        $failedBackupsCount = $this->firstInt($summary, [
            'failedBackupsCount',
            'failed_backups_count',
            'failed_backups',
            'backup_failures',
            'failures',
            'failed_count',
            'error_count',
        ]);

        if ($failedBackupsCount === null && is_array($summary['backup_summary'] ?? null)) {
            $failedBackupsCount = $this->firstInt($summary['backup_summary'], ['failed', 'failures', 'error']);
        }

        return [
            'protected_mailboxes' => $protectedMailboxes,
            'last_backup_status' => $this->backupStatus($summary, $failedBackupsCount),
            'failed_backups_count' => $failedBackupsCount,
        ];
    }

    /**
     * @param  array<string, mixed>  $list
     * @return array<string, mixed>|null
     */
    private function mapFromList(array $list, string $organizationId): ?array
    {
        $rows = $list['data'] ?? $list['results'] ?? $list['accounts'] ?? $list['organizations'] ?? $list;

        if (! is_array($rows)) {
            return null;
        }

        if (! array_is_list($rows)) {
            return $this->mapPayload($list, $organizationId);
        }

        foreach ($rows as $row) {
            if (! is_array($row) || ! $this->rowMatchesOrg($row, $organizationId)) {
                continue;
            }

            return $this->mapPayload($row, $organizationId);
        }

        // List of mailboxes for whole reseller: count those matching org and failures.
        $matched = 0;
        $failed = 0;
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            if (! $this->rowMatchesOrg($row, $organizationId) && ! $this->mailboxBelongsToOrg($row, $organizationId)) {
                continue;
            }
            $matched++;
            $status = strtolower((string) ($row['status'] ?? $row['last_backup_status'] ?? $row['backup_status'] ?? ''));
            if (in_array($status, ['failed', 'error', 'warning', 'fail'], true)) {
                $failed++;
            }
        }

        if ($matched === 0) {
            return null;
        }

        return [
            'protected_mailboxes' => $matched,
            'failed_backups_count' => $failed,
            'last_backup_status' => $failed > 0 ? 'warning' : 'success',
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function rowMatchesOrg(array $row, string $organizationId): bool
    {
        foreach (['id', 'organization_id', 'organisation_id', 'account_id', 'uuid', 'pk'] as $key) {
            if (isset($row[$key]) && (string) $row[$key] === $organizationId) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function mailboxBelongsToOrg(array $row, string $organizationId): bool
    {
        foreach (['organization_id', 'organisation_id', 'account_id', 'organization', 'account'] as $key) {
            $value = $row[$key] ?? null;
            if (is_array($value)) {
                if ($this->rowMatchesOrg($value, $organizationId)) {
                    return true;
                }
            } elseif ($value !== null && (string) $value === $organizationId) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $mapped
     */
    private function mappedHasSignal(array $mapped): bool
    {
        return ($mapped['protected_mailboxes'] ?? null) !== null
            || ($mapped['failed_backups_count'] ?? null) !== null
            || in_array($mapped['last_backup_status'] ?? 'unknown', ['success', 'warning'], true);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function summaryFromCache(
        int $clientId,
        array $payload,
        bool $isStale = false,
        bool $refreshInProgress = false,
    ): DropsuiteClientBackupSummary {
        $lastRefreshedAt = filled($payload['last_refreshed_at'] ?? null)
            ? Carbon::parse($payload['last_refreshed_at'])
            : null;

        if ($lastRefreshedAt && ! $isStale) {
            $soft = max(1, app(PortalFreshnessService::class)->effectiveSoftWindowMinutes());
            $isStale = $lastRefreshedAt->lte(now()->subMinutes($soft));
        }

        return new DropsuiteClientBackupSummary(
            protectedMailboxes: $payload['protected_mailboxes'] ?? null,
            lastBackupStatus: $this->validStatus($payload['last_backup_status'] ?? null),
            failedBackupsCount: $payload['failed_backups_count'] ?? null,
            available: true,
            unavailableReason: null,
            lastRefreshedAt: $lastRefreshedAt,
            isStale: $isStale,
            refreshInProgress: $refreshInProgress || Cache::has('dropsuite_backup.refresh_queued.'.$clientId),
        );
    }

    private function unavailableSummary(string $reason): DropsuiteClientBackupSummary
    {
        return new DropsuiteClientBackupSummary(
            protectedMailboxes: null,
            lastBackupStatus: 'unknown',
            failedBackupsCount: null,
            available: false,
            unavailableReason: $reason,
            lastRefreshedAt: null,
            isStale: false,
            refreshInProgress: false,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $keys
     */
    private function firstInt(array $payload, array $keys): ?int
    {
        foreach ($keys as $key) {
            if (isset($payload[$key]) && is_numeric($payload[$key])) {
                return (int) $payload[$key];
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function backupStatus(array $payload, ?int $failedBackupsCount): string
    {
        $status = $this->validStatus($payload['lastBackupStatus'] ?? $payload['last_backup_status'] ?? $payload['status'] ?? null);

        if ($status !== 'unknown') {
            return $status;
        }

        if ($failedBackupsCount !== null) {
            return $failedBackupsCount > 0 ? 'warning' : 'success';
        }

        return 'unknown';
    }

    private function validStatus(mixed $status): string
    {
        $status = strtolower((string) $status);

        if (in_array($status, ['ok', 'healthy', 'good'], true)) {
            return 'success';
        }

        if (in_array($status, ['error', 'failed', 'fail'], true)) {
            return 'warning';
        }

        return in_array($status, ['success', 'warning'], true) ? $status : 'unknown';
    }

    public function cacheKey(int $clientId): string
    {
        return "client:{$clientId}:dropsuite-backup:v1";
    }
}
