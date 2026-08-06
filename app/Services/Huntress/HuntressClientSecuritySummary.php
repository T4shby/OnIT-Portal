<?php

namespace App\Services\Huntress;

use Carbon\Carbon;

class HuntressClientSecuritySummary
{
    public function __construct(
        public readonly ?int $agentsTotal,
        public readonly ?int $agentsUnresponsive,
        public readonly ?int $openIncidents,
        public readonly ?int $resolvedIncidents,
        public readonly ?int $edrIsolatedAgents,
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
