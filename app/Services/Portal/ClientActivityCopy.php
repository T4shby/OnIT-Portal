<?php

namespace App\Services\Portal;

/**
 * Client-facing activity headlines from SuperOps / Huntress / Dropsuite cache.
 *
 * The home list uses ticket status only. Public replies are on the ticket page.
 * Internal notes are a separate SuperOps API and are never shown.
 */
class ClientActivityCopy
{
    /**
     * @return array{title: string, badge: string}
     */
    public function ticket(string $status): array
    {
        $key = $this->normalise($status);

        return match (true) {
            $this->containsAny($key, ['waiting on client', 'waiting on customer', 'waiting for client', 'waiting for customer']) => [
                'title' => 'We sent an update and are waiting on you',
                'badge' => 'Waiting on you',
            ],
            str_contains($key, 'waiting on vendor') || str_contains($key, 'waiting for vendor') => [
                'title' => 'We are waiting on a supplier',
                'badge' => 'Waiting on supplier',
            ],
            str_contains($key, 'waiting for schedule') => [
                'title' => 'We have booked time to work on this',
                'badge' => 'Scheduled',
            ],
            str_contains($key, 'waiting for update') => [
                'title' => 'We are waiting for more information',
                'badge' => 'Waiting for update',
            ],
            str_contains($key, 'in progress') => [
                'title' => 'A technician is working on this',
                'badge' => 'In progress',
            ],
            $key === 'on hold' || str_starts_with($key, 'on hold') => [
                'title' => 'We have paused this ticket',
                'badge' => 'On hold',
            ],
            $key === 'pending' => [
                'title' => 'We are lining up the next step',
                'badge' => 'Pending',
            ],
            $key === 'reopened' => [
                'title' => 'We have reopened this ticket',
                'badge' => 'Reopened',
            ],
            str_contains($key, 'escalat') => [
                'title' => 'We have escalated this internally',
                'badge' => 'Escalated',
            ],
            $key === 'open' => [
                'title' => 'This is in our support queue',
                'badge' => 'Open',
            ],
            str_contains($key, 'no response') => [
                'title' => 'We closed this after no reply',
                'badge' => 'Closed',
            ],
            $key === 'cancelled' || $key === 'canceled' => [
                'title' => 'This ticket was cancelled',
                'badge' => 'Cancelled',
            ],
            in_array($key, ['closed', 'resolved', 'completed'], true) => [
                'title' => 'We closed this ticket',
                'badge' => 'Closed',
            ],
            default => [
                'title' => 'We updated this ticket',
                'badge' => $status !== '' ? $status : 'Updated',
            ],
        };
    }

    /**
     * Public SuperOps conversation types only. Internal notes use a different API and are not labelled here.
     */
    public function conversation(string $type): string
    {
        return match (strtoupper(trim($type))) {
            'DESCRIPTION' => 'We logged this request',
            'REQ_REPLY' => 'You replied',
            'TECH_REPLY' => 'A technician replied',
            'REQ_NOTIFICATION' => 'You were notified',
            'TECH_NOTIFICATION' => 'A technician sent an update',
            default => 'Update',
        };
    }

    /**
     * @return array{title: string, badge: string}
     */
    public function securityCase(bool $isActive): array
    {
        if ($isActive) {
            return [
                'title' => 'We are investigating a security case',
                'badge' => 'Open',
            ];
        }

        return [
            'title' => 'We closed a security case',
            'badge' => 'Closed',
        ];
    }

    /**
     * @return array{title: string, badge: string, detail: string}
     */
    public function threatResponse(string $action, string $status): array
    {
        $actionKey = $this->normalise($action);
        $statusKey = $this->normalise($status);
        $actionLabel = $this->humanAction($action);

        $needsYou = in_array($statusKey, ['unapproved', 'pending', 'needs approval'], true)
            || str_contains($statusKey, 'unapproved')
            || str_contains($statusKey, 'awaiting')
            || str_contains($statusKey, 'needs approval');
        $done = in_array($statusKey, ['completed', 'complete', 'approved', 'success', 'applied', 'done'], true)
            || str_ends_with($statusKey, ' completed')
            || str_ends_with($statusKey, ' complete');
        $declined = in_array($statusKey, ['rejected', 'declined', 'denied'], true)
            || str_contains($statusKey, 'rejected')
            || str_contains($statusKey, 'declined');

        if (str_contains($actionKey, 'isolat')) {
            $title = $done
                ? 'We isolated a device'
                : ($needsYou ? 'Please approve isolating a device' : 'We started isolating a device');
        } elseif ($this->containsAny($actionKey, ['rotat', 'credential', 'password', 'reset'])) {
            $title = $done
                ? 'We rotated credentials'
                : ($needsYou ? 'Please approve rotating credentials' : 'We started rotating credentials');
        } elseif (str_contains($actionKey, 'contain')) {
            $title = $done
                ? 'We contained a threat'
                : ($needsYou ? 'Please approve containing a threat' : 'We started containing a threat');
        } else {
            $title = $done
                ? 'We applied a security fix'
                : ($needsYou ? 'Please approve a security fix' : 'We started a security fix');
        }

        if ($declined) {
            $title = 'A security fix was declined';
        }

        $badge = match (true) {
            $needsYou => 'Needs your approval',
            $done => 'Completed',
            $declined => 'Declined',
            $status !== '' => ucfirst(str_replace('_', ' ', $status)),
            default => 'In progress',
        };

        return [
            'title' => $title,
            'badge' => $badge,
            'detail' => $actionLabel,
        ];
    }

    /**
     * @return array{title: string, badge: string}
     */
    public function backup(string $status): array
    {
        $key = $this->normalise($status);

        return match (true) {
            str_contains($key, 'fail') => [
                'title' => 'We are checking a failed mailbox backup',
                'badge' => 'Failed',
            ],
            str_contains($key, 'retry') => [
                'title' => 'We are retrying a mailbox backup',
                'badge' => 'Retrying',
            ],
            default => [
                'title' => 'We are checking a mailbox backup',
                'badge' => $status !== '' ? ucfirst($status) : 'Needs attention',
            ],
        };
    }

    private function humanAction(string $action): string
    {
        $label = trim(str_replace('_', ' ', $action));
        if ($label === '') {
            return 'Security action';
        }

        return ucfirst($label);
    }

    private function normalise(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;

        return $value;
    }

    /**
     * @param  list<string>  $needles
     */
    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }
}
