<?php

namespace App\Services\Gdap;

use Carbon\Carbon;

/**
 * Pure decision for one customer tenant: what the relationship state is, and
 * the single Graph write (if any) the audit should make.
 *
 * Graph status "expiring" means the end date has already passed. Our status
 * "expiring" means the relationship is still active, the group is assigned,
 * and the end date is inside the warning window with auto-extend off.
 */
class GdapRelationshipAssessor
{
    /**
     * @param  list<array<string, mixed>>  $relationships  already filtered to this customer
     * @param  array<string, list<array<string, mixed>>>  $assignmentsByRelationshipId
     * @param  list<string>  $requiredRoleIds
     * @return array<string, mixed>
     */
    public function assess(
        array $relationships,
        array $assignmentsByRelationshipId,
        string $groupId,
        array $requiredRoleIds,
        \DateTimeInterface $now,
        int $expiringWithinDays,
    ): array {
        $active = $this->byStatus($relationships, ['active']);
        $inFlight = $this->byStatus($relationships, ['created', 'approvalpending', 'approved', 'activating']);

        $complete = array_values(array_filter(
            $active,
            fn (array $rel): bool => GdapRoleSet::covers($rel['role_ids'] ?? [], $requiredRoleIds),
        ));

        if ($complete !== []) {
            return $this->assessComplete(
                $this->latestEnding($complete),
                $assignmentsByRelationshipId,
                $groupId,
                $requiredRoleIds,
                $now,
                $expiringWithinDays,
            );
        }

        if ($inFlight !== []) {
            return $this->assessInFlight($this->preferInFlight($inFlight));
        }

        if ($active !== []) {
            $best = $this->latestEnding($active);
            $missing = GdapRoleSet::missingNames($best['role_ids'] ?? []);

            return $this->plan(
                action: 'create',
                status: 'incomplete',
                blocks: true,
                relationship: $best,
                summary: 'Active GDAP is missing '.implode(', ', $missing).'. A new request will ask for the full standing set. Roles on an approved relationship cannot be added later.',
                missingRoles: $missing,
            );
        }

        return $this->plan(
            action: 'create',
            status: 'missing',
            blocks: true,
            relationship: null,
            summary: 'No usable GDAP relationship. A request will be created for the standing roles.',
        );
    }

    /**
     * @param  array<string, mixed>  $relationship
     * @param  array<string, list<array<string, mixed>>>  $assignmentsByRelationshipId
     * @param  list<string>  $requiredRoleIds
     * @return array<string, mixed>
     */
    private function assessComplete(
        array $relationship,
        array $assignmentsByRelationshipId,
        string $groupId,
        array $requiredRoleIds,
        \DateTimeInterface $now,
        int $expiringWithinDays,
    ): array {
        $assignments = $assignmentsByRelationshipId[$relationship['id']] ?? [];
        $groupId = strtolower(trim($groupId));
        $ours = array_values(array_filter(
            $assignments,
            fn (array $row): bool => strtolower((string) ($row['group_id'] ?? '')) === $groupId && $groupId !== '',
        ));

        $activeAssignment = $this->firstStatus($ours, 'active');
        $pendingAssignment = $this->firstStatus($ours, 'pending');
        $autoOn = strtoupper((string) ($relationship['auto_extend'] ?? '')) === 'P180D';
        $hasGlobalAdmin = in_array(
            GdapRoleSet::GLOBAL_ADMINISTRATOR,
            array_map('strtolower', $relationship['role_ids'] ?? []),
            true,
        );
        $endsSoon = $this->endsWithin($relationship['end_at'] ?? null, $now, $expiringWithinDays);

        if ($activeAssignment !== null && GdapRoleSet::covers($activeAssignment['role_ids'] ?? [], $requiredRoleIds)) {
            $action = 'none';
            $summary = 'GDAP is active and the technician security group is assigned.';
            if (! $autoOn && ! $hasGlobalAdmin) {
                $action = 'auto_extend';
                $summary = 'GDAP is active. Auto-extend will be turned on so it adds 180 days at the end date.';
            } elseif (! $autoOn && $hasGlobalAdmin) {
                $summary = 'GDAP is active. This relationship includes Global Administrator, so Microsoft will not auto-extend it.';
            } elseif ($autoOn) {
                $summary = 'GDAP is active. Auto-extend adds 180 days when the end date is reached.';
            }

            $status = ($endsSoon && ! $autoOn) ? 'expiring' : 'ready';
            if ($status === 'expiring' && $hasGlobalAdmin) {
                $summary .= ' It ends inside '.$expiringWithinDays.' days. Request a replacement without Global Administrator before then.';
            } elseif ($status === 'expiring') {
                $summary .= ' It ends inside '.$expiringWithinDays.' days.';
            }

            return $this->plan(
                action: $action,
                status: $status,
                blocks: false,
                relationship: $relationship,
                summary: $summary,
                accessAssigned: true,
                autoExtend: $autoOn,
                assignment: $activeAssignment,
            );
        }

        if ($pendingAssignment !== null) {
            return $this->plan(
                action: 'none',
                status: 'unassigned',
                blocks: true,
                relationship: $relationship,
                summary: 'The technician security group assignment is pending in Microsoft. Connect stays blocked until it is active.',
                autoExtend: $autoOn,
            );
        }

        if ($groupId === '') {
            return $this->plan(
                action: 'none',
                status: 'unassigned',
                blocks: true,
                relationship: $relationship,
                summary: 'GDAP is active but GDAP_TECHNICIAN_GROUP_ID is not set, so the technician group cannot be assigned.',
                autoExtend: $autoOn,
            );
        }

        if ($activeAssignment !== null) {
            return $this->plan(
                action: 'update_assignment',
                status: 'unassigned',
                blocks: true,
                relationship: $relationship,
                summary: 'The technician group is assigned, but not to every standing role. The assignment will be updated.',
                missingRoles: GdapRoleSet::missingNames($activeAssignment['role_ids'] ?? []),
                autoExtend: $autoOn,
                assignment: $activeAssignment,
            );
        }

        return $this->plan(
            action: 'assign',
            status: 'unassigned',
            blocks: true,
            relationship: $relationship,
            summary: 'GDAP is approved. The technician security group will be assigned to the standing roles.',
            autoExtend: $autoOn,
        );
    }

    /**
     * @param  array<string, mixed>  $relationship
     * @return array<string, mixed>
     */
    private function assessInFlight(array $relationship): array
    {
        $status = strtolower((string) ($relationship['status'] ?? ''));

        if ($status === 'created') {
            return $this->plan(
                action: 'lock',
                status: 'approval_pending',
                blocks: true,
                relationship: $relationship,
                summary: 'A GDAP request exists and will be sent for approval.',
            );
        }

        if (in_array($status, ['approved', 'activating'], true)) {
            return $this->plan(
                action: 'none',
                status: 'approval_pending',
                blocks: true,
                relationship: $relationship,
                summary: 'The customer approved GDAP. Microsoft is still activating it.',
            );
        }

        return $this->plan(
            action: 'none',
            status: 'approval_pending',
            blocks: true,
            relationship: $relationship,
            summary: 'Waiting for a Global Admin in the customer tenant to approve GDAP. The portal cannot approve its own request.',
            approvalUrl: true,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $relationships
     * @param  list<string>  $statuses  lowercase
     * @return list<array<string, mixed>>
     */
    private function byStatus(array $relationships, array $statuses): array
    {
        return array_values(array_filter(
            $relationships,
            fn (array $rel): bool => in_array(strtolower((string) ($rel['status'] ?? '')), $statuses, true),
        ));
    }

    /**
     * @param  list<array<string, mixed>>  $relationships
     * @return array<string, mixed>
     */
    private function latestEnding(array $relationships): array
    {
        usort($relationships, function (array $a, array $b): int {
            return strcmp((string) ($b['end_at'] ?? ''), (string) ($a['end_at'] ?? ''));
        });

        return $relationships[0];
    }

    /**
     * Prefer a request that still needs the customer, then one we still have to lock.
     *
     * @param  list<array<string, mixed>>  $relationships
     * @return array<string, mixed>
     */
    private function preferInFlight(array $relationships): array
    {
        $rank = [
            'approvalpending' => 0,
            'created' => 1,
            'approved' => 2,
            'activating' => 3,
        ];
        usort($relationships, function (array $a, array $b) use ($rank): int {
            $ra = $rank[strtolower((string) ($a['status'] ?? ''))] ?? 9;
            $rb = $rank[strtolower((string) ($b['status'] ?? ''))] ?? 9;

            return $ra <=> $rb;
        });

        return $relationships[0];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>|null
     */
    private function firstStatus(array $rows, string $status): ?array
    {
        foreach ($rows as $row) {
            if (strtolower((string) ($row['status'] ?? '')) === $status) {
                return $row;
            }
        }

        return null;
    }

    private function endsWithin(mixed $endAt, \DateTimeInterface $now, int $days): bool
    {
        if (! is_string($endAt) || $endAt === '') {
            return false;
        }

        try {
            $end = Carbon::parse($endAt);
        } catch (\Throwable) {
            return false;
        }

        return $end->lessThanOrEqualTo(Carbon::instance(\DateTimeImmutable::createFromInterface($now))->addDays($days));
    }

    /**
     * @param  array<string, mixed>|null  $relationship
     * @param  list<string>  $missingRoles
     * @param  array<string, mixed>|null  $assignment
     * @return array<string, mixed>
     */
    private function plan(
        string $action,
        string $status,
        bool $blocks,
        ?array $relationship,
        string $summary,
        array $missingRoles = [],
        bool $accessAssigned = false,
        bool $autoExtend = false,
        bool $approvalUrl = false,
        ?array $assignment = null,
    ): array {
        $relationship = $relationship ?? [];
        $relationshipId = $relationship['id'] ?? null;

        return [
            'action' => $action,
            'status' => $status,
            'blocks_connect' => $blocks,
            'relationship_id' => $relationshipId,
            'display_name' => $relationship['display_name'] ?? null,
            'relationship_status' => $relationship['status'] ?? null,
            'end_at' => $relationship['end_at'] ?? null,
            'etag' => $relationship['etag'] ?? null,
            'auto_extend' => $autoExtend,
            'access_assigned' => $accessAssigned,
            'approval_url' => ($approvalUrl && filled($relationshipId))
                ? 'https://admin.microsoft.com/AdminPortal/Home#/partners/invitation/granularAdminRelationships/'.$relationshipId
                : null,
            'missing_roles' => $missingRoles,
            'summary' => $summary,
            'assignment_id' => $assignment['id'] ?? null,
            'assignment_etag' => $assignment['etag'] ?? null,
            'role_ids' => $relationship['role_ids'] ?? [],
        ];
    }
}
