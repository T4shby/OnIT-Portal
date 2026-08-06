<?php

namespace App\Services\Huntress;

use Carbon\Carbon;

/**
 * Normalised Huntress incident report (list/detail row).
 */
class HuntressIncident
{
    /**
     * @param  list<string>  $indicatorTypes
     * @param  list<array{action: string, status: string}>  $remediations
     * @param  list<string>  $relatedEmails
     */
    public function __construct(
        public readonly string $id,
        public readonly string $subject,
        public readonly string $status,
        public readonly bool $isActive,
        public readonly ?string $severity,
        public readonly ?string $summary,
        public readonly ?string $body,
        public readonly ?Carbon $sentAt,
        public readonly ?Carbon $closedAt,
        public readonly ?Carbon $updatedAt,
        public readonly ?string $platform,
        public readonly array $indicatorTypes = [],
        public readonly array $remediations = [],
        public readonly string $organizationId = '',
        public readonly array $relatedEmails = [],
    ) {}

    public function statusLabel(): string
    {
        return $this->isActive ? 'Active' : 'Resolved';
    }
}
