<?php

namespace App\Services\EntraSync;

class EntraSyncResult
{
    public function __construct(
        public readonly int $created = 0,
        public readonly int $updated = 0,
        public readonly int $deactivated = 0,
        public readonly int $skipped = 0,
        public readonly array $errors = [],
    ) {}

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    public function totalChanged(): int
    {
        return $this->created + $this->updated + $this->deactivated;
    }

    public function summary(bool $dryRun = false): string
    {
        $prefix = $dryRun ? 'Dry run: ' : '';

        return sprintf(
            '%screated %d, updated %d, deactivated %d, skipped %d.',
            $prefix,
            $this->created,
            $this->updated,
            $this->deactivated,
            $this->skipped,
        );
    }

    public function failed(): bool
    {
        return $this->hasErrors() && $this->totalChanged() === 0 && $this->skipped === 0;
    }
}
