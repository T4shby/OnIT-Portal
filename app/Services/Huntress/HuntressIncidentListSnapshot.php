<?php

namespace App\Services\Huntress;

use Carbon\Carbon;

class HuntressIncidentListSnapshot
{
    /**
     * @param  list<HuntressIncident>  $incidents
     */
    public function __construct(
        public readonly array $incidents,
        public readonly int $activeCount,
        public readonly int $resolvedCount,
        public readonly bool $available,
        public readonly ?string $unavailableReason,
        public readonly ?Carbon $lastRefreshedAt,
        public readonly bool $isStale = false,
        public readonly bool $refreshInProgress = false,
    ) {}

    public function hasData(): bool
    {
        return $this->available && $this->lastRefreshedAt !== null;
    }
}
