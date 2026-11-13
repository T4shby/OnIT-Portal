<?php

namespace App\Services\Gdap;

use App\Models\Client;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Staff-only view of clients.gdap_snapshot. Does not call Graph.
 */
class GdapSnapshotPresenter
{
    /**
     * @var array<string, string>
     */
    public const STATUS_LABELS = [
        'ready' => 'Active',
        'expiring' => 'Expiring',
        'approval_pending' => 'Awaiting approval',
        'unassigned' => 'No security group',
        'incomplete' => 'Missing roles',
        'missing' => 'No relationship',
        'needs_tenant' => 'Tenant unknown',
        'unchecked' => 'Not checked',
        'error' => 'Check failed',
    ];

    /**
     * @return array<string, mixed>
     */
    public function forClient(Client $client): array
    {
        $enabled = (bool) config('services.gdap.enabled');
        if (! $enabled) {
            return [
                'enabled' => false,
                'configured' => false,
                'status' => 'off',
                'status_label' => 'Off',
                'blocks_connect' => false,
                'summary' => '',
                'approval_url' => null,
                'checked_at' => null,
                'end_label' => null,
                'customer_tenant_id' => null,
            ];
        }

        $configured = app(GdapGraphClient::class)->isConfigured();
        $snap = is_array($client->gdap_snapshot) ? $client->gdap_snapshot : null;

        if ($snap === null) {
            $canTarget = filled($client->entra_tenant_id) || filled($client->primary_domain);

            return [
                'enabled' => true,
                'configured' => $configured,
                'status' => $configured ? 'unchecked' : 'error',
                'status_label' => $configured ? 'Not checked' : 'Not configured',
                'blocks_connect' => $configured && $canTarget,
                'summary' => $configured
                    ? 'GDAP has not been checked yet. Use Check GDAP now before Connect Microsoft.'
                    : 'GDAP is on but the partner app is not configured. Set GDAP_PARTNER_TENANT_ID and the app id and secret. Connect Microsoft is not gated.',
                'approval_url' => null,
                'checked_at' => null,
                'end_label' => null,
                'customer_tenant_id' => filled($client->entra_tenant_id) ? (string) $client->entra_tenant_id : null,
            ];
        }

        $status = (string) ($snap['status'] ?? 'unchecked');

        return [
            'enabled' => true,
            'configured' => $configured,
            'status' => $status,
            'status_label' => self::STATUS_LABELS[$status] ?? $status,
            'blocks_connect' => (bool) ($snap['blocks_connect'] ?? false),
            'summary' => (string) ($snap['summary'] ?? ''),
            'approval_url' => $this->approvalUrl($snap['approval_url'] ?? null),
            'checked_at' => $snap['checked_at'] ?? null,
            'end_label' => $this->endLabel($snap['end_at'] ?? null),
            'customer_tenant_id' => $snap['customer_tenant_id'] ?? null,
            'missing_roles' => $snap['missing_roles'] ?? [],
        ];
    }

    /**
     * @param  Collection<int, Client>  $clients
     * @return array<string, mixed>
     */
    public function portfolio(Collection $clients): array
    {
        $enabled = (bool) config('services.gdap.enabled');
        $empty = [
            'enabled' => $enabled,
            'configured' => false,
            'headline' => null,
            'counts' => [],
            'problems' => [],
            'problem_count' => 0,
            'ready_count' => 0,
        ];

        if (! $enabled) {
            return $empty;
        }

        $configured = app(GdapGraphClient::class)->isConfigured();
        if (! $configured) {
            return [
                'enabled' => true,
                'configured' => false,
                'headline' => 'GDAP is on but the partner app is not configured. Set GDAP_PARTNER_TENANT_ID and the app id and secret.',
                'counts' => [],
                'problems' => [],
                'problem_count' => 0,
                'ready_count' => 0,
            ];
        }

        $counts = array_fill_keys(array_keys(self::STATUS_LABELS), 0);
        $problems = [];

        foreach ($clients as $client) {
            $view = $this->forClient($client);
            $status = (string) $view['status'];
            if (! array_key_exists($status, $counts)) {
                $counts[$status] = 0;
            }
            $counts[$status]++;

            if ($status === 'ready') {
                continue;
            }

            $problems[] = [
                'client_id' => $client->id,
                'client_name' => $client->name,
                'status' => $status,
                'status_label' => $view['status_label'],
                'summary' => $view['summary'],
            ];
        }

        $rank = [
            'error' => 0,
            'missing' => 1,
            'incomplete' => 2,
            'unassigned' => 3,
            'approval_pending' => 4,
            'expiring' => 5,
            'needs_tenant' => 6,
            'unchecked' => 7,
        ];
        usort($problems, function (array $a, array $b) use ($rank): int {
            $ra = $rank[$a['status']] ?? 9;
            $rb = $rank[$b['status']] ?? 9;

            return $ra <=> $rb ?: strcasecmp($a['client_name'], $b['client_name']);
        });

        $problemCount = count($problems);
        $parts = [];
        foreach ([
            'approval_pending' => 'awaiting approval',
            'unassigned' => 'with no security group',
            'incomplete' => 'missing roles',
            'missing' => 'with no relationship',
            'expiring' => 'expiring',
            'unchecked' => 'not checked',
            'needs_tenant' => 'with no tenant id',
            'error' => 'check failed',
        ] as $status => $label) {
            $n = (int) ($counts[$status] ?? 0);
            if ($n > 0) {
                $parts[] = $n.' '.$label;
            }
        }

        return [
            'enabled' => true,
            'configured' => true,
            'headline' => $parts === [] ? null : 'GDAP: '.implode(', ', $parts).'.',
            'counts' => $counts,
            'problems' => array_slice($problems, 0, 12),
            'problem_count' => $problemCount,
            'ready_count' => (int) ($counts['ready'] ?? 0),
        ];
    }

    private function approvalUrl(mixed $url): ?string
    {
        if (! is_string($url) || ! str_starts_with($url, 'https://admin.microsoft.com/')) {
            return null;
        }

        return $url;
    }

    private function endLabel(mixed $endAt): ?string
    {
        if (! is_string($endAt) || $endAt === '') {
            return null;
        }

        try {
            return Carbon::parse($endAt)->timezone('Europe/London')->format('j M Y');
        } catch (\Throwable) {
            return null;
        }
    }
}
