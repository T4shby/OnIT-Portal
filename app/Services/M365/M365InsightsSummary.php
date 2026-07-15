<?php

namespace App\Services\M365;

use Carbon\Carbon;

class M365InsightsSummary
{
    /**
     * @param  list<array{skuPartNumber: string, purchased: int, assigned: int, utilizationPct: float}>  $topSkus
     */
    public function __construct(
        public readonly ?int $licensedUserCount,
        public readonly ?int $totalSeatsPurchased,
        public readonly ?int $totalSeatsAssigned,
        public readonly ?float $overallUtilizationPct,
        public readonly array $topSkus,
        public readonly ?Carbon $lastRefreshedAt,
        public readonly bool $isStale,
        public readonly bool $refreshInProgress,
        public readonly ?string $unavailableReason,
    ) {}

    public function hasData(): bool
    {
        return $this->lastRefreshedAt !== null && $this->unavailableReason === null;
    }
}
