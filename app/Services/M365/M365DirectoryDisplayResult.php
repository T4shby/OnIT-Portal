<?php

namespace App\Services\M365;

use Carbon\Carbon;

class M365DirectoryDisplayResult
{
    public function __construct(
        public readonly ?M365DirectorySnapshot $snapshot,
        public readonly bool $isStale,
        public readonly bool $refreshQueued,
        public readonly bool $refreshInProgress,
        public readonly ?Carbon $lastRefreshedAt,
        public readonly ?string $statusMessage = null,
    ) {}
}
