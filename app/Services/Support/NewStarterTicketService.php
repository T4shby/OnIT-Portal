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
     *     notes?: string|null,
     *     security_groups?: list<string>,
     *     security_group_names?: list<string>
     * }  $data
     * @return array<string, mixed>
     */
    public function submit(User $user, array $data): array
    {
        $name = trim((string) $data['starter_name']);
        $subject = 'New starter request: '.$name;
        $description = $this->buildDescription($user, $data);

        // buildDescription() escapes every submitted value, so the result is trusted HTML.
        return $this->tickets->createTicket($user, $subject, $description, descriptionIsHtml: true);
    }

    /**
     * HTML for SuperOps (plain newlines are collapsed in the PSA). All values escaped.
     *
     * @param  array<string, mixed>  $data
     */
    public function buildDescription(User $user, array $data): string
    {
        $requestedBy = trim($user->name.' ('.$user->email.')');

        $rows = [
            $this->row('Requested by', $requestedBy),
            $this->row('Organisation', (string) ($user->client?->name ?: '-')),
            $this->row('Starter name', trim((string) ($data['starter_name'] ?? ''))),
            $this->row('Job title', $this->dash($data['job_title'] ?? null)),
            $this->row('Start date', $this->dash($data['start_date'] ?? null)),
            $this->row('Department / team', $this->dash($data['department'] ?? null)),
            $this->row('Line manager', $this->dash($data['manager_name'] ?? null)),
            $this->row('Email to create (if known)', $this->dash($data['starter_email'] ?? null)),
            $this->row('Security groups', $this->securityGroups($data)),
        ];

        return implode('', [
            '<p><strong>New starter request</strong><br>Submitted via On IT Portal</p>',
            '<ul>'.implode('', $rows).'</ul>',
            $this->block('Equipment / access needed', $data['equipment_access'] ?? null),
            $this->block('Additional notes', $data['notes'] ?? null),
        ]);
    }

    private function row(string $label, string $value): string
    {
        return '<li><strong>'.e($label).':</strong> '.e($value).'</li>';
    }

    private function block(string $label, ?string $value): string
    {
        return '<p><strong>'.e($label).'</strong><br>'.nl2br(e($this->dash($value)), false).'</p>';
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function securityGroups(array $data): string
    {
        $names = [];
        foreach ($data['security_group_names'] ?? [] as $name) {
            $name = trim((string) $name);
            if ($name !== '') {
                $names[] = $name;
            }
        }

        if ($names !== []) {
            return implode(', ', $names);
        }

        $requested = array_filter((array) ($data['security_groups'] ?? []), static fn ($id): bool => trim((string) $id) !== '');
        if ($requested !== []) {
            return 'Selected on the form, but Microsoft could not confirm the names. Ask the requester which groups they ticked.';
        }

        return '-';
    }

    private function dash(?string $value): string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : '-';
    }
}
