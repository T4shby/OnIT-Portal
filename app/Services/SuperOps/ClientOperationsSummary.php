<?php

namespace App\Services\SuperOps;

use Carbon\Carbon;

class ClientOperationsSummary
{
    /**
     * @param  array<string, int|null>  $ticketsCreated
     * @param  array<string, int|null>  $ticketsClosed
     */
    public function __construct(
        public readonly ?int $assetsTotal,
        public readonly ?int $openTicketsTotal,
        public readonly array $ticketsCreated,
        public readonly array $ticketsClosed,
        public readonly ?Carbon $lastRefreshedAt,
        public readonly bool $isStale,
        public readonly bool $refreshInProgress,
        public readonly ?string $unavailableReason = null,
    ) {}

    public function hasData(): bool
    {
        return $this->unavailableReason === null && $this->lastRefreshedAt !== null;
    }
}
