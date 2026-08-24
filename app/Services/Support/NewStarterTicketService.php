<?php

namespace App\Services\Support;

use App\Models\User;
use App\Services\SuperOps\SuperOpsTicketService;

class NewStarterTicketService
{
    public function __construct(
        private SuperOpsTicketService $tickets,
    ) {}

    /**
     * @param  array{
     *     starter_name: string,
     *     job_title?: string|null,
     *     start_date?: string|null,
     *     department?: string|null,
     *     manager_name?: string|null,
     *     starter_email?: string|null,
     *     equipment_access?: string|null,
     *     notes?: string|null
     * }  $data
     * @return array<string, mixed>
     */
    public function submit(User $user, array $data): array
    {
        $name = trim((string) $data['starter_name']);
        $subject = 'New starter request: '.$name;
        $description = $this->buildDescription($user, $data);

        return $this->tickets->createTicket($user, $subject, $description);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function buildDescription(User $user, array $data): string
    {
        $lines = [
            'NEW STARTER REQUEST (submitted via On IT Portal)',
            '',
            'Requested by: '.$user->name.' <'.$user->email.'>',
            'Organisation: '.($user->client?->name ?? '-'),
            '',
            'Starter name: '.trim((string) ($data['starter_name'] ?? '')),
            'Job title: '.$this->dash($data['job_title'] ?? null),
            'Start date: '.$this->dash($data['start_date'] ?? null),
            'Department / team: '.$this->dash($data['department'] ?? null),
            'Line manager: '.$this->dash($data['manager_name'] ?? null),
            'Email to create (if known): '.$this->dash($data['starter_email'] ?? null),
            '',
            'Equipment / access needed:',
            $this->block($data['equipment_access'] ?? null),
            '',
            'Additional notes:',
            $this->block($data['notes'] ?? null),
        ];

        return implode("\n", $lines);
    }

    private function dash(?string $value): string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : '-';
    }

    private function block(?string $value): string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : '-';
    }
}
