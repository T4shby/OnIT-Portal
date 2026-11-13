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
        public readonly ?int $itdrIdentityCount = null,
        public readonly bool $itdrKnown = false,
    ) {}

    public function hasData(): bool
    {
        return $this->available && $this->lastRefreshedAt !== null;
    }

    /**
     * EDR is deployed and Huntress reported no ITDR identities.
     * Unknown (the ITDR block was not in the payload) is not an opportunity.
     */
    public function edrWithoutItdr(): bool
    {
        return $this->itdrKnown
            && ($this->itdrIdentityCount ?? 0) === 0
            && ($this->agentsTotal ?? 0) > 0;
    }
}
