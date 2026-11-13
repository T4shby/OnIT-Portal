<?php

namespace App\Services\Gdap;

use App\Models\Client;
use App\Services\ActivityLogService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Reads partner-tenant GDAP, then creates, locks, assigns, or extends so each
 * active portal client ends on the standing role set.
 */
class GdapReconcileService
{
    public function __construct(
        private GdapGraphClient $graph,
        private GdapRelationshipAssessor $assessor,
        private ActivityLogService $activityLog,
    ) {}

    /**
     * @return array{checked: int, failed: int}
     */
    public function auditAll(?int $clientId = null): array
    {
        if (! config('services.gdap.enabled')) {
            return ['checked' => 0, 'failed' => 0];
        }

        if (! Schema::hasColumn('clients', 'gdap_snapshot')) {
            Log::warning('GDAP audit skipped: clients.gdap_snapshot is missing. Run migrations.');

            return ['checked' => 0, 'failed' => 0];
        }

        $query = Client::query()->where('is_active', true)->orderBy('id');
        if ($clientId !== null) {
            $query->whereKey($clientId);
        }

        $clients = $query->get();
        if ($clients->isEmpty()) {
            return ['checked' => 0, 'failed' => 0];
        }

        if (! $this->graph->isConfigured()) {
            $failed = 0;
            foreach ($clients as $client) {
                $this->persist($client, $this->notConfiguredSnapshot($client), null);
                $failed++;
            }

            return ['checked' => $clients->count(), 'failed' => $failed];
        }

        try {
            $relationships = $this->graph->listRelationships();
        } catch (\Throwable $e) {
            $message = $this->safeMessage($e);
            Log::warning('GDAP relationship list failed', ['error' => $message]);
            foreach ($clients as $client) {
                $this->persist($client, [
                    'status' => 'error',
                    'summary' => 'Could not list GDAP relationships. '.$message,
                    'error' => $message,
                ], $client->gdap_snapshot);
            }

            return ['checked' => $clients->count(), 'failed' => $clients->count()];
        }

        $assignments = [];
        $checked = 0;
        $failed = 0;

        foreach ($clients as $client) {
            $checked++;
            try {
                $this->reconcileClient($client, $relationships, $assignments);
            } catch (\Throwable $e) {
                $failed++;
                $message = $this->safeMessage($e);
                Log::warning('GDAP reconcile failed', [
                    'client_id' => $client->id,
                    'error' => $message,
                ]);
                $this->persist($client, [
                    'status' => 'error',
                    'summary' => 'GDAP check failed. '.$message,
                    'error' => $message,
                ], $client->gdap_snapshot);
            }
        }

        return ['checked' => $checked, 'failed' => $failed];
    }

    /**
     * @param  list<array<string, mixed>>  $relationships
     * @param  array<string, list<array<string, mixed>>>  $assignmentCache
     */
    private function reconcileClient(Client $client, array &$relationships, array &$assignmentCache): void
    {
        $lock = Cache::lock('gdap-client-'.$client->id, 90);
        if (! $lock->get()) {
            return;
        }

        try {
            $tenantId = $this->tenantIdFor($client);
            if ($tenantId === null) {
                $this->persist($client, [
                    'status' => 'needs_tenant',
                    'blocks_connect' => false,
                    'summary' => 'No Entra tenant id, and the main domain is not a Microsoft tenant. Paste the tenant id on the left, then Check GDAP now.',
                ], null);

                return;
            }

            $mine = array_values(array_filter(
                $relationships,
                fn (array $rel): bool => ($rel['customer_tenant_id'] ?? '') === $tenantId,
            ));

            foreach ($mine as $rel) {
                if (strtolower((string) ($rel['status'] ?? '')) !== 'active') {
                    continue;
                }
                if (! array_key_exists($rel['id'], $assignmentCache)) {
                    $assignmentCache[$rel['id']] = $this->graph->listAccessAssignments($rel['id']);
                }
            }

            $groupId = $this->technicianGroupId();
            $plan = $this->assessor->assess(
                $mine,
                $assignmentCache,
                $groupId,
                GdapRoleSet::ids(),
                now(),
                (int) config('services.gdap.expiring_within_days', 30),
            );

            $plan['customer_tenant_id'] = $tenantId;
            $actionTaken = null;

            if ($plan['action'] === 'lock' && filled($plan['relationship_id'])) {
                $this->graph->lockForApproval((string) $plan['relationship_id']);
                $plan['status'] = 'approval_pending';
                $plan['approval_url'] = $this->graph->approvalUrl((string) $plan['relationship_id']);
                $plan['summary'] = 'Waiting for a Global Admin in the customer tenant to approve GDAP. The portal cannot approve its own request.';
                $plan['blocks_connect'] = true;
                $actionTaken = 'locked';
            }

            if ($plan['action'] === 'create') {
                $created = $this->createRequest($client, $tenantId, $mine);
                if (! filled($created['id'] ?? null)) {
                    throw new GdapGraphException('GDAP create returned no relationship id.');
                }
                $this->graph->lockForApproval($created['id']);
                $created['status'] = 'approvalPending';
                $created['customer_tenant_id'] = $tenantId;
                $relationships[] = $created;
                $plan['relationship_id'] = $created['id'];
                $plan['display_name'] = $created['display_name'];
                $plan['relationship_status'] = 'approvalPending';
                $plan['status'] = 'approval_pending';
                $plan['approval_url'] = $this->graph->approvalUrl($created['id']);
                $plan['blocks_connect'] = true;
                $plan['summary'] = 'Waiting for a Global Admin in the customer tenant to approve GDAP. The portal cannot approve its own request.';
                $actionTaken = 'created';
            }

            if ($plan['action'] === 'assign' && filled($plan['relationship_id'])) {
                $assignment = $this->graph->createAccessAssignment(
                    (string) $plan['relationship_id'],
                    $this->technicianGroupId(),
                    $this->rolesToAssign($plan),
                );
                $this->applyAssignmentResult($plan, $assignment);
                $actionTaken = 'assigned';
            }

            if ($plan['action'] === 'update_assignment' && filled($plan['relationship_id']) && filled($plan['assignment_id'])) {
                $this->graph->updateAccessAssignment(
                    (string) $plan['relationship_id'],
                    (string) $plan['assignment_id'],
                    $this->rolesToAssign($plan),
                    $plan['assignment_etag'] ?? null,
                );
                $plan['status'] = 'unassigned';
                $plan['blocks_connect'] = true;
                $plan['access_assigned'] = false;
                $plan['summary'] = 'The technician group assignment was updated. Connect stays blocked until Microsoft marks it active.';
                $actionTaken = 'assignment_updated';
            }

            if ($plan['action'] === 'auto_extend' && filled($plan['relationship_id'])) {
                try {
                    $this->graph->enableAutoExtend((string) $plan['relationship_id'], $plan['etag'] ?? null);
                    $plan['auto_extend'] = true;
                    $plan['status'] = 'ready';
                    $plan['blocks_connect'] = false;
                    $plan['summary'] = 'GDAP is active and auto-extend is on. It adds 180 days when the end date is reached.';
                    $actionTaken = $actionTaken ?? 'auto_extended';
                } catch (GdapGraphException $e) {
                    $plan['summary'] .= ' Auto-extend was refused: '.$e->getMessage();
                    $plan['error'] = $e->getMessage();
                }
            }

            $this->persist($client, $plan, null);

            if ($actionTaken !== null) {
                $this->activityLog->log('client.gdap_'.$actionTaken, $client, [
                    'relationship_id' => $plan['relationship_id'],
                    'status' => $plan['status'],
                ], $client->id);
            }
        } finally {
            $lock->release();
        }
    }

    private function technicianGroupId(): string
    {
        return strtolower(trim((string) config('services.gdap.technician_group_id'), " \t\n\r\0\x0B{}"));
    }

    /**
     * @param  list<array<string, mixed>>  $existing
     * @return array<string, mixed>
     */
    private function createRequest(Client $client, string $tenantId, array $existing): array
    {
        $name = $this->displayName($client, $existing);

        try {
            return $this->graph->createRelationship($name, $tenantId, $client->name, GdapRoleSet::ids());
        } catch (GdapGraphException $e) {
            if (! in_array($e->statusCode, [400, 409], true)) {
                throw $e;
            }

            return $this->graph->createRelationship(
                mb_substr($name.'-'.now()->format('His'), 0, 50),
                $tenantId,
                $client->name,
                GdapRoleSet::ids(),
            );
        }
    }

    /**
     * @param  list<array<string, mixed>>  $existing
     */
    private function displayName(Client $client, array $existing): string
    {
        $base = 'On IT '.$client->id.' '.now()->format('ymd');
        $taken = array_map(
            fn (array $rel): string => strtolower((string) ($rel['display_name'] ?? '')),
            $existing,
        );
        $name = $base;
        $n = 2;
        while (in_array(strtolower($name), $taken, true) && $n < 10) {
            $name = $base.'-'.$n;
            $n++;
        }

        return mb_substr($name, 0, 50);
    }

    /**
     * Assign the standing roles that this relationship actually contains.
     *
     * @param  array<string, mixed>  $plan
     * @return list<string>
     */
    private function rolesToAssign(array $plan): array
    {
        $have = array_map('strtolower', $plan['role_ids'] ?? []);
        $wanted = array_values(array_filter(
            GdapRoleSet::ids(),
            fn (string $id): bool => in_array($id, $have, true),
        ));

        return $wanted !== [] ? $wanted : GdapRoleSet::ids();
    }

    /**
     * @param  array<string, mixed>  $plan
     * @param  array<string, mixed>  $assignment
     */
    private function applyAssignmentResult(array &$plan, array $assignment): void
    {
        $active = strtolower((string) ($assignment['status'] ?? '')) === 'active'
            && GdapRoleSet::covers($assignment['role_ids'] ?? [], GdapRoleSet::ids());

        if ($active) {
            $plan['status'] = 'ready';
            $plan['blocks_connect'] = false;
            $plan['access_assigned'] = true;
            $plan['summary'] = 'GDAP is active and the technician security group is assigned.';

            return;
        }

        $plan['status'] = 'unassigned';
        $plan['blocks_connect'] = true;
        $plan['access_assigned'] = false;
        $plan['summary'] = 'The technician security group assignment was sent. Connect stays blocked until Microsoft marks it active.';
    }

    private function tenantIdFor(Client $client): ?string
    {
        $saved = $this->normalizeTenantId($client->entra_tenant_id);
        if ($saved !== null) {
            return $saved;
        }

        $snapshot = is_array($client->gdap_snapshot) ? $client->gdap_snapshot : [];
        $cachedDomain = strtolower((string) ($snapshot['resolved_domain'] ?? ''));
        $domain = strtolower(trim((string) $client->primary_domain));
        if ($cachedDomain !== '' && $cachedDomain === $domain) {
            $cached = $this->normalizeTenantId($snapshot['customer_tenant_id'] ?? null);
            if ($cached !== null) {
                return $cached;
            }
        }

        if ($domain === '') {
            return null;
        }

        return $this->graph->resolveTenantIdFromDomain($domain);
    }

    private function normalizeTenantId(mixed $id): ?string
    {
        $id = strtolower(trim((string) $id, " \t\n\r\0\x0B{}"));

        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $id) === 1
            ? $id
            : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function notConfiguredSnapshot(Client $client): array
    {
        return [
            'status' => 'error',
            'blocks_connect' => false,
            'summary' => 'GDAP is on but the partner app is not configured. Set GDAP_PARTNER_TENANT_ID and the app id and secret. Connect Microsoft is not gated.',
            'error' => 'gdap_not_configured',
            'customer_tenant_id' => $this->normalizeTenantId($client->entra_tenant_id),
        ];
    }

    /**
     * @param  array<string, mixed>  $fields
     * @param  array<string, mixed>|null  $previous  when set, a failed check keeps the previous block
     */
    private function persist(Client $client, array $fields, ?array $previous): void
    {
        $previous = is_array($previous) ? $previous : [];
        $failed = ($fields['status'] ?? '') === 'error' && $previous !== [];

        $snapshot = [
            'status' => (string) ($fields['status'] ?? 'error'),
            'blocks_connect' => (bool) ($fields['blocks_connect'] ?? false),
            'customer_tenant_id' => $fields['customer_tenant_id'] ?? ($previous['customer_tenant_id'] ?? null),
            'resolved_domain' => filled($client->primary_domain) ? strtolower(trim((string) $client->primary_domain)) : null,
            'relationship_id' => $fields['relationship_id'] ?? null,
            'display_name' => $fields['display_name'] ?? null,
            'relationship_status' => $fields['relationship_status'] ?? null,
            'end_at' => $fields['end_at'] ?? null,
            'auto_extend' => (bool) ($fields['auto_extend'] ?? false),
            'access_assigned' => (bool) ($fields['access_assigned'] ?? false),
            'approval_url' => $this->safeApprovalUrl($fields['approval_url'] ?? null),
            'missing_roles' => array_values($fields['missing_roles'] ?? []),
            'summary' => (string) ($fields['summary'] ?? ''),
            'error' => $fields['error'] ?? null,
            'checked_at' => Carbon::now()->toIso8601String(),
        ];

        if ($failed && ($previous['blocks_connect'] ?? false)) {
            $snapshot['blocks_connect'] = true;
            $snapshot['status'] = (string) ($previous['status'] ?? 'error');
            $snapshot['approval_url'] = $this->safeApprovalUrl($previous['approval_url'] ?? null);
            $snapshot['relationship_id'] = $previous['relationship_id'] ?? null;
            $snapshot['summary'] = trim((string) ($previous['summary'] ?? '').' Last check failed: '.$snapshot['summary']);
        }

        $client->update(['gdap_snapshot' => $snapshot]);
        $client->gdap_snapshot = $snapshot;
    }

    private function safeApprovalUrl(mixed $url): ?string
    {
        if (! is_string($url) || ! str_starts_with($url, 'https://admin.microsoft.com/')) {
            return null;
        }

        return $url;
    }

    private function safeMessage(\Throwable $e): string
    {
        $message = preg_replace('/Bearer\s+\S+/i', 'Bearer [redacted]', $e->getMessage()) ?? $e->getMessage();

        return mb_substr(trim($message), 0, 400);
    }
}
