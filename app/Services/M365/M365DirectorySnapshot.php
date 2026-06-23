<?php

namespace App\Services\M365;

use Carbon\Carbon;
use Illuminate\Support\Collection;

class M365DirectorySnapshot
{
    /**
     * @param  Collection<int, array{displayName: string, email: ?string, type: string, typeLabel: string, accountEnabled: bool, licenses: list<string>, portalLogin: bool}>  $people
     * @param  Collection<int, array{displayName: string, email: ?string, type: string, typeLabel: string, description: ?string}>  $groups
     */
    public function __construct(
        public readonly Collection $people,
        public readonly Collection $groups,
        public readonly Carbon $refreshedAt,
    ) {}

    public function peopleCounts(): array
    {
        return [
            'all' => $this->people->count(),
            'users' => $this->people->where('type', 'user')->count(),
            'shared_mailboxes' => $this->people->where('type', 'shared_mailbox')->count(),
        ];
    }

    public function groupCounts(): array
    {
        return [
            'all' => $this->groups->count(),
            'security_group' => $this->groups->where('type', 'security_group')->count(),
            'distribution_list' => $this->groups->where('type', 'distribution_list')->count(),
            'microsoft_365_group' => $this->groups->where('type', 'microsoft_365_group')->count(),
            'mail_enabled_security_group' => $this->groups->where('type', 'mail_enabled_security_group')->count(),
        ];
    }
}
