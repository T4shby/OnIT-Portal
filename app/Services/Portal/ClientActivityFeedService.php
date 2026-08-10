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
    ) {}

    /**
     * @return list<array{at: string, title: string, detail: string, source: string}>
     */
    public function recentFor(Client $client, ?User $viewer = null, int $limit = 8): array
    {
        $events = [];

        $so = $this->superOps->summaryForClient($client, false, $viewer);
        if ($so->hasData() || $so->openTicketsTable !== []) {
            foreach (array_slice($so->openTicketsTable, 0, 12) as $ticket) {
                $created = $ticket['createdTime'] ?? null;
                if (! is_string($created) || $created === '') {
                    continue;
                }
                $display = trim((string) ($ticket['displayId'] ?? ''));
                $subject = trim((string) ($ticket['subject'] ?? 'Support ticket'));
                $status = trim((string) ($ticket['status'] ?? ''));
                $events[] = [
                    'at' => $created,
                    'title' => $display !== '' ? "Ticket {$display}" : 'Support ticket',
                    'detail' => $this->truncate($subject.($status !== '' ? " · {$status}" : ''), 120),
                    'source' => 'support',
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
                $title = $incident->isActive
                    ? 'Security case open'
                    : 'Security case closed';
                $subject = trim($incident->subject !== '' ? $incident->subject : 'Security investigation');
                $events[] = [
                    'at' => $when->toIso8601String(),
                    'title' => $title,
                    'detail' => $this->truncate($subject, 120),
                    'source' => 'security',
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
                    $events[] = [
                        'at' => $when->toIso8601String(),
                        'title' => 'Threat response',
                        'detail' => $this->truncate(
                            ucfirst(str_replace('_', ' ', $action)).($status !== '' ? " · {$status}" : ''),
                            120,
                        ),
                        'source' => 'security',
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
                $status = trim((string) ($failure['current_backup_status'] ?? 'needs attention'));
                $events[] = [
                    'at' => $when,
                    'title' => 'Backup attention',
                    'detail' => $this->truncate("{$user} · {$status}", 120),
                    'source' => 'backup',
                ];
            }
        }

        usort($events, function (array $a, array $b): int {
            return $this->sortKey($b['at']) <=> $this->sortKey($a['at']);
        });

        return array_slice($events, 0, $limit);
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
