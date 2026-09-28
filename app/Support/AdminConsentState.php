<?php

namespace App\Support;

/**
 * Signed `state` for the Microsoft admin-consent round trip.
 *
 * The consent callback is reachable without a portal session (staff Accept in a
 * private browser), so the client id it acts on must come from a value only this
 * app can mint. Unsigned legacy `client-{id}` states are rejected: accepting them
 * let anyone re-point any client's Entra tenant with a hand-crafted URL.
 */
class AdminConsentState
{
    public static function encode(int $clientId): string
    {
        $payload = (string) $clientId;

        return $payload.'.'.hash_hmac('sha256', $payload, self::signingKey());
    }

    public static function decode(?string $state): ?int
    {
        if (! is_string($state) || $state === '' || ! str_contains($state, '.')) {
            return null;
        }

        [$payload, $signature] = explode('.', $state, 2);
        $clientId = (int) $payload;

        if ($clientId <= 0 || (string) $clientId !== $payload || ! hash_equals(self::sign($payload), $signature)) {
            return null;
        }

        return $clientId;
    }

    private static function sign(string $payload): string
    {
        return hash_hmac('sha256', $payload, self::signingKey());
    }

    private static function signingKey(): string
    {
        return (string) config('app.key');
    }
}
