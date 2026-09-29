<?php

namespace App\Support;

use Illuminate\Database\Query\Builder;

/**
 * Helpers for finding a client's job in the database `jobs` table.
 *
 * A queued job's `payload` column is JSON, and the serialized command inside it
 * is a JSON string, so its quotes are stored escaped: `s:8:\"clientId\";i:5;`.
 * A LIKE on `clientId";i:5;` therefore never matches a real row. A backslash in a
 * LIKE pattern is an escape character on MySQL but a literal on SQLite, so the
 * escaped form is matched with the single-character wildcard `_` instead, which
 * behaves the same on both.
 */
final class QueuedJobPayload
{
    public static function whereClientId(Builder $query, int $clientId): Builder
    {
        return $query->where(function (Builder $q) use ($clientId): void {
            $q->where('payload', 'like', '%clientId_";i:'.$clientId.';%')
                ->orWhere('payload', 'like', '%clientId";i:'.$clientId.';%');
        });
    }
}
