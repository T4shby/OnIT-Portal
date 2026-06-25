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
        public readonly int $superOpsNameHintsUpdated = 0,
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
            + $this->superOpsNameHintsUpdated
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
                '; SuperOps app +%d / -%d users',
                $this->superOpsAppUsersAssigned,
                $this->superOpsAppUsersRemoved,
            );
        }

        if ($this->superOpsNameHintsUpdated > 0) {
            $parts .= sprintf('; SuperOps last names updated %d', $this->superOpsNameHintsUpdated);
        }

        if ($this->superOpsUsersProvisioned > 0) {
            $parts .= sprintf('; SuperOps SCIM provisioned %d', $this->superOpsUsersProvisioned);
        }

        return $parts.'.';
    }

    public function failed(): bool
    {
        return $this->hasErrors() && $this->totalChanged() === 0 && $this->skipped === 0;
    }
}
