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
}
