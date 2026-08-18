<?php

namespace App\Services\EntraSync;

class EntraSyncResult
{
    public function __construct(
        public readonly int $created = 0,
        public readonly int $updated = 0,
        public readonly int $deactivated = 0,
        public readonly int $skipped = 0,
        public readonly int $groupMembersAdded = 0,
        public readonly int $groupMembersRemoved = 0,
        public readonly int $superOpsAppUsersAssigned = 0,
        public readonly int $superOpsAppUsersRemoved = 0,
        public readonly int $requesterSsoUsersAssigned = 0,
        public readonly int $requesterSsoUsersRemoved = 0,
        public readonly int $superOpsNameHintsUpdated = 0,
        public readonly int $superOpsApiNamesUpdated = 0,
        public readonly int $superOpsEmailsUpdated = 0,
        public readonly int $superOpsIdsBound = 0,
        public readonly int $superOpsEmailsUnmatched = 0,
        public readonly int $superOpsUsersProvisioned = 0,
        public readonly array $errors = [],
    ) {}

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    public function totalChanged(): int
    {
        return $this->created + $this->updated + $this->deactivated
            + $this->groupMembersAdded + $this->groupMembersRemoved
            + $this->superOpsAppUsersAssigned + $this->superOpsAppUsersRemoved
            + $this->requesterSsoUsersAssigned + $this->requesterSsoUsersRemoved
            + $this->superOpsNameHintsUpdated
            + $this->superOpsApiNamesUpdated
            + $this->superOpsEmailsUpdated
            + $this->superOpsIdsBound
            + $this->superOpsUsersProvisioned;
    }

    public function summary(bool $dryRun = false): string
    {
        $prefix = $dryRun ? 'Dry run: ' : '';

        $parts = sprintf(
            '%screated %d, updated %d, deactivated %d, skipped %d',
            $prefix,
            $this->created,
            $this->updated,
            $this->deactivated,
            $this->skipped,
        );

        if ($this->groupMembersAdded > 0 || $this->groupMembersRemoved > 0) {
            $parts .= sprintf(
                '; SuperOps group +%d / -%d members',
                $this->groupMembersAdded,
                $this->groupMembersRemoved,
            );
        }

        if ($this->superOpsAppUsersAssigned > 0 || $this->superOpsAppUsersRemoved > 0) {
            $parts .= sprintf(
                '; SuperOps SCIM app +%d / -%d users',
                $this->superOpsAppUsersAssigned,
                $this->superOpsAppUsersRemoved,
            );
        }

        if ($this->requesterSsoUsersAssigned > 0 || $this->requesterSsoUsersRemoved > 0) {
            $parts .= sprintf(
                '; SuperOps SSO access +%d / -%d active licensed users',
                $this->requesterSsoUsersAssigned,
                $this->requesterSsoUsersRemoved,
            );
        }

        if ($this->superOpsNameHintsUpdated > 0) {
            $parts .= sprintf('; SuperOps last names updated %d', $this->superOpsNameHintsUpdated);
        }

        if ($this->superOpsApiNamesUpdated > 0) {
            $parts .= sprintf(
                '; SuperOps API names fixed %d (hybrid Graph fallback)',
                $this->superOpsApiNamesUpdated,
            );
        }

        if ($this->superOpsEmailsUpdated > 0 || $this->superOpsIdsBound > 0 || $this->superOpsEmailsUnmatched > 0) {
            $parts .= sprintf(
                '; SuperOps email align: %d updated, %d SuperOps ids bound',
                $this->superOpsEmailsUpdated,
                $this->superOpsIdsBound,
            );
            if ($this->superOpsEmailsUnmatched > 0) {
                $parts .= sprintf(', %d unmatched', $this->superOpsEmailsUnmatched);
            }
        }

        if ($this->superOpsUsersProvisioned > 0) {
            $parts .= sprintf(
                '; SuperOps SCIM provision requested for %d changed user(s) - check Entra provisioning logs',
                $this->superOpsUsersProvisioned,
            );
        }

        return $parts.'.';
    }

    public function failed(): bool
    {
        return $this->hasErrors() && $this->totalChanged() === 0 && $this->skipped === 0;
    }

    public function hasWarnings(): bool
    {
        return $this->hasErrors() && ! $this->failed();
    }
}
