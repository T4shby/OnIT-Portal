<?php

namespace App\Services\SuperOps;

use Carbon\Carbon;

class ClientOperationsSummary
{
    /**
     * @param  array<string, int|null>  $ticketsCreated
     * @param  array<string, int|null>  $ticketsClosed
     * @param  array<string, int>  $openTicketsByPriority
     * @param  list<array{displayId: string, subject: string, priority: string, status: string, createdTime: ?string, updatedTime?: ?string, category?: string}>  $openTicketsTable
     * @param  list<array{displayId: string, subject: string, priority: string, status: string, createdTime: ?string, updatedTime?: ?string, resolutionTime: ?string, category?: string}>  $closedTicketsTable
     * @param  array<string, int>  $ticketsByCategory
     * @param  array<string, mixed>  $deviceInsights
     */
    public function __construct(
        public readonly ?int $assetsTotal,
        public readonly ?int $assetsOnline,
        public readonly ?int $assetsOffline,
        public readonly ?int $openTicketsTotal,
        public readonly array $openTicketsByPriority,
        public readonly array $openTicketsTable,
        public readonly ?int $slaMetPercent,
        public readonly ?int $slaSampleSize,
        public readonly array $ticketsCreated,
        public readonly array $ticketsClosed,
        public readonly ?Carbon $lastRefreshedAt,
        public readonly bool $isStale,
        public readonly bool $refreshInProgress,
        public readonly ?string $unavailableReason = null,
        public readonly ?int $waitingOnClientTotal = null,
        public readonly array $closedTicketsTable = [],
        public readonly array $ticketsByCategory = [],
        public readonly array $deviceInsights = [],
    ) {}

    public function hasData(): bool
    {
        return $this->unavailableReason === null && $this->lastRefreshedAt !== null;
    }
}
