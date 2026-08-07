<?php

namespace App\Services\Dropsuite;

use App\Jobs\RefreshDropsuiteBackupJob;
use App\Models\Client;
use App\Models\User;
use App\Services\Portal\ClearsOrphanedFeedRefreshFlags;
use App\Services\Portal\ClientVisibilityService;
use App\Services\Portal\PortalFreshnessService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Dropsuite / NinjaOne SaaS Backup metrics (sub-reseller REST API v1).
 *
 * Auth headers (PDF): X-Reseller-Token + X-Access-Token.
 * Primary source: GET /accounts (last_backup, current_backup_status, user.organization_id).
 * Optional enrichment: GET /onedrives (per-email / org list).
 *
 * @see Brain/ClientAdminDashboard.md
 * @see App\Contracts\DashboardFeed (DropsuiteDashboardFeed adapter)
 */
class DropsuiteClientMetricsService
{
    use ClearsOrphanedFeedRefreshFlags;

    private const STALE_RETENTION_MINUTES = 1440;

    private const CACHE_VERSION = 'v3';

    private const MAX_ACCOUNT_PAGES = 40;

    public function __construct(
        private DropsuiteApiClient $api,
        private ClientVisibilityService $visibility,
    ) {}

    public function isAvailable(): bool
    {
        return $this->api->isConfigured();
    }

    public function summaryForClient(
        Client $client,
        bool $manualRefresh = false,
        ?User $viewer = null,
    ): DropsuiteClientBackupSummary {
        if (! app(\App\Services\Portal\ClientProductService::class)->isEntitled($client, 'dropsuite')) {
            return $this->unavailableSummary('Dropsuite is not sold for this organisation.');
        }

        if (! $this->isAvailable()) {
            return $this->unavailableSummary('Dropsuite API is not configured.');
        }

        if (empty($client->dropsuite_organization_id)) {
            return $this->unavailableSummary('Dropsuite is not connected for this organisation.');
        }

        $cacheKey = $this->cacheKey($client->id);
        $cached = Cache::get($cacheKey);

        if (is_array($cached) && ! $manualRefresh) {
            return $this->summaryFromCache($client, $cached, viewer: $viewer);
        }

        if ($manualRefresh) {
            $this->queueRefresh($client);
            $cached = Cache::get($cacheKey);
            if (is_array($cached)) {
                return $this->summaryFromCache($client, $cached, refreshInProgress: true, viewer: $viewer);
            }
        } else {
            $this->queueRefresh($client);
        }

        if (is_array($cached)) {
            return $this->summaryFromCache($client, $cached, refreshInProgress: true, viewer: $viewer);
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
        if (! app(\App\Services\Portal\ClientProductService::class)->shouldRefresh($client, 'dropsuite')) {
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
        if (! app(\App\Services\Portal\ClientProductService::class)->shouldRefresh($client, 'dropsuite')) {
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
                return $this->summaryFromCache($client, $cached, refreshInProgress: true);
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

            // Drop legacy v1 cache so Integration Health / UI do not mix schemas.
            Cache::forget("client:{$client->id}:dropsuite-backup:v1");

            return $this->summaryFromCache($client, $payload);
        } catch (Throwable $e) {
            Log::warning('Dropsuite backup refresh failed', [
                'client_id' => $client->id,
                'organization_id' => $organizationId,
                'error' => $e->getMessage(),
            ]);

            $cached = Cache::get($this->cacheKey($client->id));
            if (is_array($cached)) {
                return $this->summaryFromCache($client, $cached, isStale: true);
            }

            // No snapshot yet: rethrow so RefreshDropsuiteBackupJob records last_result
            // success=false (was silently "success" while Integration Health stayed Never loaded).
            throw $e;
        } finally {
            Cache::forget('dropsuite_backup.refresh_queued.'.$client->id);
            $lock->release();
        }
    }

    /**
     * Sub-reseller API: resolve org user token via GET /users, then GET /accounts.
     *
     * Admin Authentication Token can list users but returns empty /accounts.
     * Each customer “user” row exposes authentication_token used as User Token.
     *
     * @return array<string, mixed>
     */
    private function fetchOrganizationBackupSummary(string $organizationId): array
    {
        $accessToken = $this->userAccessTokenForOrganization($organizationId);
        $accounts = $this->fetchAccountsForOrganization($organizationId, $accessToken);
        $emails = array_values(array_filter(array_map(
            static fn (array $row): string => (string) ($row['email'] ?? ''),
            $accounts,
        )));
        $onedrives = $this->fetchOptionalResourceRows('onedrives', $emails, $accessToken);
        $sharepoints = $this->fetchOptionalResourceRows('sharepoints', $emails, $accessToken);
        // Alternate path used by some Dropsuite tenants
        if ($sharepoints === []) {
            $sharepoints = $this->fetchOptionalResourceRows('sites', $emails, $accessToken);
        }

        return $this->mapAccountsPayload($accounts, $onedrives, $sharepoints, $organizationId);
    }

    /**
     * Partner Admin Authentication Token → GET /users → per-org User Token.
     */
    private function userAccessTokenForOrganization(string $organizationId): string
    {
        $users = Cache::remember('dropsuite.users.list.v1', now()->addMinutes(15), function (): array {
            return $this->fetchAllUsers();
        });

        $match = null;
        foreach ($users as $user) {
            if (! is_array($user)) {
                continue;
            }
            $oid = (string) ($user['organization_id'] ?? $user['organisation_id'] ?? '');
            // API sends int; staff mapping is a string. Reject dotted/user-id shapes separately below.
            if ($oid === '' || $oid !== (string) $organizationId) {
                continue;
            }
            $token = trim((string) ($user['authentication_token'] ?? ''));
            if ($token === '') {
                continue;
            }
            // Prefer admin customer user; otherwise first with a token.
            if ($match === null || ! empty($user['admin'])) {
                $match = $token;
                if (! empty($user['admin'])) {
                    break;
                }
            }
        }

        if ($match === null) {
            $hint = str_contains($organizationId, '-')
                ? ' Mapped value looks like a Dropsuite user/plan id (…-12), not organization_id. Use the numeric organization_id from GET /users (Admin → Clients map; e.g. YorPower is 6182).'
                : ' Confirm clients.dropsuite_organization_id is the numeric organization_id from the reseller GET /users list.';

            throw new \RuntimeException(
                'Dropsuite has no user access token for organization '.$organizationId.'.'.$hint
            );
        }

        return $match;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchAllUsers(): array
    {
        $users = [];

        for ($page = 1; $page <= 20; $page++) {
            $payload = $this->api->get('users', ['page' => $page, 'per_page' => 100]);
            $rows = $this->extractListRows($payload);
            if ($rows === []) {
                break;
            }
            foreach ($rows as $row) {
                if (is_array($row)) {
                    $users[] = $row;
                }
            }
            $pagination = is_array($payload['pagination'] ?? null) ? $payload['pagination'] : [];
            $totalPages = (int) ($pagination['total_pages'] ?? $page);
            if ($page >= $totalPages) {
                break;
            }
        }

        return $users;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchAccountsForOrganization(string $organizationId, string $accessToken): array
    {
        $matched = [];

        for ($page = 1; $page <= self::MAX_ACCOUNT_PAGES; $page++) {
            $payload = $this->api->get(
                'accounts',
                ['page' => $page, 'per_page' => 100],
                accessToken: $accessToken,
            );
            $rows = $this->extractListRows($payload);

            if ($rows === []) {
                break;
            }

            foreach ($rows as $row) {
                if (! is_array($row)) {
                    continue;
                }
                // User token scopes to one org; still filter when API returns mixed rows.
                if ($this->accountBelongsToOrganization($row, $organizationId)
                    || ! $this->rowHasOrganizationHint($row)) {
                    $matched[] = $row;
                }
            }

            $pagination = is_array($payload['pagination'] ?? null) ? $payload['pagination'] : [];
            $totalPages = (int) ($pagination['total_pages'] ?? 0);
            if ($totalPages > 0) {
                if ($page >= $totalPages) {
                    break;
                }
                continue;
            }

            if (! $this->payloadHasNextPage($payload, $page, count($rows))) {
                break;
            }
        }

        return $matched;
    }

    /**
     * @param  list<string>  $emails
     * @return list<array<string, mixed>>
     */
    private function fetchOptionalResourceRows(string $path, array $emails, ?string $accessToken = null): array
    {
        try {
            $out = [];
            for ($page = 1; $page <= 20; $page++) {
                $payload = $this->api->get($path, ['page' => $page, 'per_page' => 100], accessToken: $accessToken);
                $rows = $this->extractListRows($payload);
                if ($rows === []) {
                    break;
                }
                foreach ($rows as $row) {
                    if (is_array($row)) {
                        $out[] = $row;
                    }
                }
                $pagination = is_array($payload['pagination'] ?? null) ? $payload['pagination'] : [];
                $totalPages = (int) ($pagination['total_pages'] ?? $page);
                if ($page >= $totalPages) {
                    break;
                }
            }

            if ($emails === [] || $out === []) {
                return $out;
            }

            // Prefer org-scoped rows; if API returns tenant-wide, filter by protected mailbox emails when present.
            $wanted = array_fill_keys(array_map('strtolower', $emails), true);
            $filtered = [];
            $hadEmail = false;
            foreach ($out as $row) {
                $email = strtolower(trim((string) ($row['email'] ?? $row['owner_email'] ?? $row['user_email'] ?? '')));
                if ($email === '') {
                    $filtered[] = $row;

                    continue;
                }
                $hadEmail = true;
                if (isset($wanted[$email])) {
                    $filtered[] = $row;
                }
            }

            return $hadEmail ? $filtered : $out;
        } catch (Throwable $e) {
            Log::info('Dropsuite optional resource skipped', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * @param  list<string>  $emails
     * @return list<array<string, mixed>>
     */
    private function fetchOneDriveRowsForEmails(array $emails, ?string $accessToken = null): array
    {
        return $this->fetchOptionalResourceRows('onedrives', $emails, $accessToken);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function rowHasOrganizationHint(array $row): bool
    {
        if (isset($row['organization_id']) || isset($row['organisation_id'])) {
            return true;
        }
        $user = $row['user'] ?? null;

        return is_array($user) && (isset($user['organization_id']) || isset($user['organisation_id']));
    }

    /**
     * @param  list<array<string, mixed>>  $accounts
     * @param  list<array<string, mixed>>  $onedrives
     * @param  list<array<string, mixed>>  $sharepoints
     * @return array<string, mixed>
     */
    public function mapAccountsPayload(
        array $accounts,
        array $onedrives,
        array $sharepoints,
        string $organizationId,
    ): array {
        $normalized = [];
        $failed = 0;
        $succeededLast24h = 0;
        $failedLast24h = 0;
        $latestBackup = null;
        $since = now()->subDay();

        foreach ($accounts as $row) {
            $email = trim((string) ($row['email'] ?? ''));
            if ($email === '') {
                continue;
            }

            $lastBackupRaw = $row['last_backup'] ?? $row['last_backup_at'] ?? null;
            $lastBackupAt = filled($lastBackupRaw) ? Carbon::parse((string) $lastBackupRaw) : null;
            if ($lastBackupAt && ($latestBackup === null || $lastBackupAt->gt($latestBackup))) {
                $latestBackup = $lastBackupAt;
            }

            $errors = $row['errors'] ?? [];
            $hasErrors = is_array($errors) ? $errors !== [] : filled($errors);
            $status = trim((string) ($row['current_backup_status'] ?? $row['backup_status'] ?? ''));
            $isFailed = $hasErrors || $this->statusLooksFailed($status);
            if ($isFailed) {
                $failed++;
            }

            if ($lastBackupAt !== null && $lastBackupAt->gte($since)) {
                if ($isFailed) {
                    $failedLast24h++;
                } else {
                    $succeededLast24h++;
                }
            }

            $normalized[] = [
                'email' => $email,
                'display_name' => filled($row['display_name'] ?? null) ? (string) $row['display_name'] : null,
                'last_backup_at' => $lastBackupAt?->toIso8601String(),
                'current_backup_status' => $status !== '' ? $status : null,
                'has_errors' => $isFailed,
            ];
        }

        usort($normalized, static function (array $a, array $b): int {
            return strcmp((string) ($b['last_backup_at'] ?? ''), (string) ($a['last_backup_at'] ?? ''));
        });

        $onedriveRows = $this->normalizeSecondaryRows($onedrives);
        $sharepointRows = $this->normalizeSecondaryRows($sharepoints);

        return [
            'organization_id' => $organizationId,
            'protected_mailboxes' => count($normalized),
            'failed_backups_count' => $failed,
            'succeeded_last_24h' => $succeededLast24h,
            'failed_last_24h' => $failedLast24h,
            'last_backup_status' => $failed > 0 ? 'warning' : ($normalized === [] ? 'unknown' : 'success'),
            'last_backup_at' => $latestBackup?->toIso8601String(),
            'onedrive_count' => count($onedriveRows),
            'sharepoint_count' => count($sharepointRows),
            'accounts' => $normalized,
            'onedrives' => $onedriveRows,
            'sharepoints' => $sharepointRows,
            'source_path' => 'accounts',
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{name: string, email: ?string, last_backup_at: ?string, status: ?string, has_errors: bool}>
     */
    private function normalizeSecondaryRows(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $name = trim((string) (
                $row['name']
                ?? $row['site_name']
                ?? $row['display_name']
                ?? $row['url']
                ?? $row['email']
                ?? $row['owner_email']
                ?? ''
            ));
            if ($name === '') {
                continue;
            }
            $lastBackupRaw = $row['last_backup'] ?? $row['last_backup_at'] ?? null;
            $lastBackupAt = filled($lastBackupRaw) ? Carbon::parse((string) $lastBackupRaw) : null;
            $status = trim((string) ($row['current_backup_status'] ?? $row['backup_status'] ?? $row['status'] ?? ''));
            $errors = $row['errors'] ?? [];
            $hasErrors = (is_array($errors) ? $errors !== [] : filled($errors))
                || $this->statusLooksFailed($status);

            $out[] = [
                'name' => $name,
                'email' => filled($row['email'] ?? $row['owner_email'] ?? null)
                    ? (string) ($row['email'] ?? $row['owner_email'])
                    : null,
                'last_backup_at' => $lastBackupAt?->toIso8601String(),
                'status' => $status !== '' ? $status : null,
                'has_errors' => $hasErrors,
            ];
        }

        usort($out, static fn (array $a, array $b): int => strcmp((string) ($b['last_backup_at'] ?? ''), (string) ($a['last_backup_at'] ?? '')));

        return $out;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<mixed>
     */
    private function extractListRows(array $payload): array
    {
        foreach (['result_set', 'data', 'results', 'accounts', 'onedrives', 'users'] as $key) {
            if (isset($payload[$key]) && is_array($payload[$key])) {
                $rows = $payload[$key];

                return array_is_list($rows) ? $rows : [$rows];
            }
        }

        if (array_is_list($payload)) {
            return $payload;
        }

        // Single account object
        if (isset($payload['email']) || isset($payload['id'])) {
            return [$payload];
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function payloadHasNextPage(array $payload, int $page, int $rowCount): bool
    {
        $pagination = $payload['pagination'] ?? null;
        if (is_array($pagination)) {
            if (isset($pagination['total_pages']) && (int) $pagination['total_pages'] > $page) {
                return true;
            }
            if (isset($pagination['next_page']) && filled($pagination['next_page'])) {
                return true;
            }
        }

        // PDF calendars/contacts: max 25 per page — keep paging while full.
        return $rowCount >= 25 && $page < self::MAX_ACCOUNT_PAGES;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function accountBelongsToOrganization(array $row, string $organizationId): bool
    {
        foreach (['organization_id', 'organisation_id'] as $key) {
            if (isset($row[$key]) && (string) $row[$key] === $organizationId) {
                return true;
            }
        }

        $user = $row['user'] ?? null;
        if (is_array($user)) {
            foreach (['organization_id', 'organisation_id'] as $key) {
                if (isset($user[$key]) && (string) $user[$key] === $organizationId) {
                    return true;
                }
            }
        }

        // Some reseller payloads use the org owner id as account id.
        foreach (['id', 'account_id'] as $key) {
            if (isset($row[$key]) && (string) $row[$key] === $organizationId) {
                return true;
            }
        }

        return false;
    }

    private function statusLooksFailed(string $status): bool
    {
        $status = strtolower($status);

        return str_contains($status, 'fail')
            || str_contains($status, 'error')
            || str_contains($status, 'timeout');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function summaryFromCache(
        Client $client,
        array $payload,
        bool $isStale = false,
        bool $refreshInProgress = false,
        ?User $viewer = null,
    ): DropsuiteClientBackupSummary {
        $lastRefreshedAt = filled($payload['last_refreshed_at'] ?? null)
            ? Carbon::parse($payload['last_refreshed_at'])
            : null;

        if ($lastRefreshedAt && ! $isStale) {
            $soft = max(1, app(PortalFreshnessService::class)->effectiveSoftWindowMinutes());
            $isStale = $lastRefreshedAt->lte(now()->subMinutes($soft));
        }

        $orgWide = $viewer === null
            || $this->visibility->canViewOrganisationWide($viewer, $client);

        $accounts = is_array($payload['accounts'] ?? null) ? $payload['accounts'] : [];
        $refreshing = $refreshInProgress || Cache::has('dropsuite_backup.refresh_queued.'.$client->id);
        $window = $this->windowCountsFromAccounts($accounts);

        if (! $orgWide && $viewer !== null) {
            return $this->personalSummaryFromAccounts(
                $viewer,
                $accounts,
                $lastRefreshedAt,
                $isStale,
                $refreshing,
                $this->validStatus($payload['last_backup_status'] ?? null),
            );
        }

        $lastBackupAt = filled($payload['last_backup_at'] ?? null)
            ? Carbon::parse($payload['last_backup_at'])
            : null;

        $onedrives = is_array($payload['onedrives'] ?? null) ? $payload['onedrives'] : [];
        $sharepoints = is_array($payload['sharepoints'] ?? null) ? $payload['sharepoints'] : [];

        return new DropsuiteClientBackupSummary(
            protectedMailboxes: isset($payload['protected_mailboxes']) ? (int) $payload['protected_mailboxes'] : count($accounts),
            lastBackupStatus: $this->validStatus($payload['last_backup_status'] ?? null),
            failedBackupsCount: isset($payload['failed_backups_count']) ? (int) $payload['failed_backups_count'] : $window['failed_open'],
            available: true,
            unavailableReason: null,
            lastRefreshedAt: $lastRefreshedAt,
            isStale: $isStale,
            refreshInProgress: $refreshing,
            scope: 'organisation',
            lastBackupAt: $lastBackupAt,
            personalEmail: null,
            accounts: $accounts,
            onedriveCount: isset($payload['onedrive_count']) ? (int) $payload['onedrive_count'] : count($onedrives),
            succeededLast24h: isset($payload['succeeded_last_24h']) ? (int) $payload['succeeded_last_24h'] : $window['succeeded_24h'],
            failedLast24h: isset($payload['failed_last_24h']) ? (int) $payload['failed_last_24h'] : $window['failed_24h'],
            onedrives: $onedrives,
            sharepoints: $sharepoints,
            sharepointCount: isset($payload['sharepoint_count']) ? (int) $payload['sharepoint_count'] : count($sharepoints),
        );
    }

    /**
     * @param  list<array<string, mixed>>  $accounts
     * @return array{succeeded_24h: int, failed_24h: int, failed_open: int}
     */
    private function windowCountsFromAccounts(array $accounts): array
    {
        $since = now()->subDay();
        $succeeded = 0;
        $failed24 = 0;
        $failedOpen = 0;

        foreach ($accounts as $row) {
            if (! is_array($row)) {
                continue;
            }
            $isFailed = ! empty($row['has_errors'])
                || $this->statusLooksFailed((string) ($row['current_backup_status'] ?? ''));
            if ($isFailed) {
                $failedOpen++;
            }
            if (! filled($row['last_backup_at'] ?? null)) {
                continue;
            }
            try {
                $at = Carbon::parse((string) $row['last_backup_at']);
            } catch (Throwable) {
                continue;
            }
            if ($at->lt($since)) {
                continue;
            }
            if ($isFailed) {
                $failed24++;
            } else {
                $succeeded++;
            }
        }

        return [
            'succeeded_24h' => $succeeded,
            'failed_24h' => $failed24,
            'failed_open' => $failedOpen,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $accounts
     */
    private function personalSummaryFromAccounts(
        User $viewer,
        array $accounts,
        ?Carbon $lastRefreshedAt,
        bool $isStale,
        bool $refreshInProgress,
        string $fallbackStatus,
    ): DropsuiteClientBackupSummary {
        $mine = null;
        foreach ($accounts as $row) {
            if (! is_array($row)) {
                continue;
            }
            if ($this->visibility->matchesEmail($row['email'] ?? null, $viewer)) {
                $mine = $row;
                break;
            }
        }

        if ($mine === null) {
            return new DropsuiteClientBackupSummary(
                protectedMailboxes: null,
                lastBackupStatus: 'unknown',
                failedBackupsCount: null,
                available: true,
                unavailableReason: null,
                lastRefreshedAt: $lastRefreshedAt,
                isStale: $isStale,
                refreshInProgress: $refreshInProgress,
                scope: 'personal',
                lastBackupAt: null,
                personalEmail: (string) $viewer->email,
                accounts: [],
            );
        }

        $lastBackupAt = filled($mine['last_backup_at'] ?? null)
            ? Carbon::parse((string) $mine['last_backup_at'])
            : null;

        $status = 'success';
        if (! empty($mine['has_errors']) || $this->statusLooksFailed((string) ($mine['current_backup_status'] ?? ''))) {
            $status = 'warning';
        } elseif ($lastBackupAt === null) {
            $status = $fallbackStatus === 'warning' ? 'warning' : 'unknown';
        }

        return new DropsuiteClientBackupSummary(
            protectedMailboxes: 1,
            lastBackupStatus: $status,
            failedBackupsCount: $status === 'warning' ? 1 : 0,
            available: true,
            unavailableReason: null,
            lastRefreshedAt: $lastRefreshedAt,
            isStale: $isStale,
            refreshInProgress: $refreshInProgress,
            scope: 'personal',
            lastBackupAt: $lastBackupAt,
            personalEmail: (string) ($mine['email'] ?? $viewer->email),
            accounts: [$mine],
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

    private function validStatus(mixed $status): string
    {
        $status = strtolower((string) $status);

        if (in_array($status, ['ok', 'healthy', 'good', 'running', 'preparing backup', 'success'], true)) {
            return 'success';
        }

        if (in_array($status, ['error', 'failed', 'fail', 'warning'], true) || $this->statusLooksFailed($status)) {
            return 'warning';
        }

        return in_array($status, ['success', 'warning'], true) ? $status : 'unknown';
    }

    public function cacheKey(int $clientId): string
    {
        return "client:{$clientId}:dropsuite-backup:".self::CACHE_VERSION;
    }
}
