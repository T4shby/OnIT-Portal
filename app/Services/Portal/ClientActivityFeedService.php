<?php

namespace App\Services\Portal;

use App\Models\Client;
use App\Models\User;
use App\Services\Dropsuite\DropsuiteClientMetricsService;
use App\Services\Huntress\HuntressIncidentService;
use App\Services\SuperOps\SuperOpsClientMetricsService;
use Carbon\Carbon;

/**
 * Composed “what we did for you” list from live feed caches (not a full event bus).
 */
class ClientActivityFeedService
{
    public function __construct(
        private SuperOpsClientMetricsService $superOps,
        private HuntressIncidentService $huntressIncidents,
        private DropsuiteClientMetricsService $dropsuite,
        private ClientActivityCopy $copy,
    ) {}

    /**
     * @return list<array{at: string, title: string, detail: string, source: string, badge: string, ref: string}>
     */
    public function recentFor(Client $client, ?User $viewer = null, int $limit = 8): array
    {
        $events = [];

        $so = $this->superOps->summaryForClient($client, false, $viewer);
        if ($so->hasData() || $so->openTicketsTable !== [] || $so->closedTicketsTable !== []) {
            $tickets = array_merge(
                array_slice($so->closedTicketsTable, 0, 8),
                array_slice($so->openTicketsTable, 0, 12),
            );
            foreach ($tickets as $ticket) {
                if (! is_array($ticket)) {
                    continue;
                }
                $when = $this->ticketWhen($ticket);
                if ($when === null) {
                    continue;
                }
                $display = trim((string) ($ticket['displayId'] ?? ''));
                $subject = trim((string) ($ticket['subject'] ?? 'Support ticket'));
                $status = trim((string) ($ticket['status'] ?? ''));
                $mapped = $this->copy->ticket($status);
                $events[] = [
                    'at' => $when,
                    'title' => $mapped['title'],
                    'detail' => $this->truncate($subject, 120),
                    'source' => 'support',
                    'badge' => $mapped['badge'],
                    'ref' => $display !== '' ? "Ticket {$display}" : 'Support ticket',
                ];
            }
        }

        if ($this->huntressIncidents->isAvailableForClient($client)) {
            $list = $this->huntressIncidents->listForClient($client, null, $viewer);
            foreach (array_slice($list->incidents, 0, 12) as $incident) {
                $when = $incident->updatedAt
                    ?? $incident->closedAt
                    ?? $incident->sentAt;
                if ($when === null) {
                    continue;
                }
                $mapped = $this->copy->securityCase($incident->isActive);
                $subject = trim($incident->subject !== '' ? $incident->subject : 'Security investigation');
                $events[] = [
                    'at' => $when->toIso8601String(),
                    'title' => $mapped['title'],
                    'detail' => $this->truncate($subject, 120),
                    'source' => 'security',
                    'badge' => $mapped['badge'],
                    'ref' => 'Security',
                ];

                foreach (array_slice($incident->remediations, 0, 3) as $remediation) {
                    if (! is_array($remediation)) {
                        continue;
                    }
                    $action = trim((string) ($remediation['action'] ?? $remediation['type'] ?? ''));
                    $status = trim((string) ($remediation['status'] ?? ''));
                    if ($action === '') {
                        continue;
                    }
                    $mappedFix = $this->copy->threatResponse($action, $status);
                    $events[] = [
                        'at' => $when->toIso8601String(),
                        'title' => $mappedFix['title'],
                        'detail' => $this->truncate($mappedFix['detail'], 120),
                        'source' => 'security',
                        'badge' => $mappedFix['badge'],
                        'ref' => 'Threat response',
                    ];
                }
            }
        }

        $bu = $this->dropsuite->summaryForClient($client, false, $viewer);
        if ($bu->hasData()) {
            $failedRows = [];
            foreach ($bu->accounts as $account) {
                if (! is_array($account) || empty($account['has_errors'])) {
                    continue;
                }
                $failedRows[] = $account;
            }
            foreach (array_slice($failedRows, 0, 6) as $failure) {
                $when = $failure['last_backup_at'] ?? null;
                if (! is_string($when) || $when === '') {
                    $when = ($bu->lastBackupAt?->toIso8601String()) ?? now()->toIso8601String();
                }
                $user = trim((string) ($failure['display_name'] ?? $failure['email'] ?? 'Mailbox'));
                $status = trim((string) ($failure['current_backup_status'] ?? ''));
                $mapped = $this->copy->backup($status);
                $events[] = [
                    'at' => $when,
                    'title' => $mapped['title'],
                    'detail' => $this->truncate($user, 120),
                    'source' => 'backup',
                    'badge' => $mapped['badge'],
                    'ref' => 'Backup',
                ];
            }
        }

        usort($events, function (array $a, array $b): int {
            return $this->sortKey($b['at']) <=> $this->sortKey($a['at']);
        });

        return array_slice($events, 0, $limit);
    }

    /**
     * @param  array<string, mixed>  $ticket
     */
    private function ticketWhen(array $ticket): ?string
    {
        foreach (['updatedTime', 'resolutionTime', 'createdTime'] as $field) {
            $value = $ticket[$field] ?? null;
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function sortKey(string $at): int
    {
        try {
            return Carbon::parse($at)->getTimestamp();
        } catch (\Throwable) {
            return 0;
        }
    }

    private function truncate(string $value, int $max): string
    {
        $value = preg_replace('/\s+/', ' ', trim($value)) ?? '';
        if (mb_strlen($value) <= $max) {
            return $value;
        }

        return rtrim(mb_substr($value, 0, $max - 1)).'…';
    }
}
