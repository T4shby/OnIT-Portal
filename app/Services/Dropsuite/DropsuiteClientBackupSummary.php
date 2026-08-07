<?php

namespace App\Services\Dropsuite;

use Carbon\Carbon;

class DropsuiteClientBackupSummary
{
    /**
     * @param  list<array{
     *   email: string,
     *   display_name: ?string,
     *   last_backup_at: ?string,
     *   current_backup_status: ?string,
     *   has_errors: bool
     * }>  $accounts
     * @param  list<array<string, mixed>>  $onedrives
     * @param  list<array<string, mixed>>  $sharepoints
     */
    public function __construct(
        public readonly ?int $protectedMailboxes,
        public readonly string $lastBackupStatus,
        public readonly ?int $failedBackupsCount,
        public readonly bool $available,
        public readonly ?string $unavailableReason,
        public readonly ?Carbon $lastRefreshedAt,
        public readonly bool $isStale,
        public readonly bool $refreshInProgress,
        public readonly string $scope = 'organisation',
        public readonly ?Carbon $lastBackupAt = null,
        public readonly ?string $personalEmail = null,
        public readonly array $accounts = [],
        public readonly ?int $onedriveCount = null,
        public readonly ?int $succeededLast24h = null,
        public readonly ?int $failedLast24h = null,
        public readonly array $onedrives = [],
        public readonly array $sharepoints = [],
        public readonly ?int $sharepointCount = null,
    ) {}

    public function hasData(): bool
    {
        return $this->available && $this->lastRefreshedAt !== null;
    }

    public function isPersonal(): bool
    {
        return $this->scope === 'personal';
    }
}
