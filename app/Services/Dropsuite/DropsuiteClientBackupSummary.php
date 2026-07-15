<?php

namespace App\Services\Dropsuite;

use Carbon\Carbon;

class DropsuiteClientBackupSummary
{
    public function __construct(
        public readonly ?int $protectedMailboxes,
        public readonly string $lastBackupStatus,
        public readonly ?int $failedBackupsCount,
        public readonly bool $available,
        public readonly ?string $unavailableReason,
        public readonly ?Carbon $lastRefreshedAt,
        public readonly bool $isStale,
        public readonly bool $refreshInProgress,
    ) {}

    public function hasData(): bool
    {
        return $this->available && $this->lastRefreshedAt !== null;
    }
}
