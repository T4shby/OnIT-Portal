<?php

namespace App\Support;

/**
 * Signed, expiring `state` for the Microsoft admin-consent round trip.
 *
 * The consent callback is reachable without a portal session (staff Accept in a
 * private browser), so the client id it acts on must come from a value only this
 * app can mint. Unsigned legacy `client-{id}` states are rejected: accepting them
 * let anyone re-point any client's Entra tenant with a hand-crafted URL.
 *
 * Format: `{clientId}.{issuedAtUnix}.{hmac}`. The HMAC covers BOTH the client id and
 * the issued-at time (with a versioned context prefix), so the timestamp on a leaked
 * link cannot be refreshed without APP_KEY. States older than the configured TTL
 * (services.entra_sync.admin_consent_link_ttl_hours, default 24h) are refused; so
 * are the pre-expiry two-part `{clientId}.{hmac}` states, which carry no timestamp.
 *
 * Why 24h: per Brain (CustomerEntraSyncRunbook "Step 4", ClientOnboarding 2026-07-14)
 * the link is opened by an On IT technician via GDAP, never sent to the customer, and
 * a fresh link is minted on every Admin → Clients → Edit page load - so an expired
 * link costs one page reload, while a long-lived one that leaks (browser history,
 * logs) could link an attacker's tenant to a not-yet-linked client.
 */
class AdminConsentState
{
    private const CONTEXT = 'admin-consent:v2:';

    /** Allowed clock skew for an issued-at slightly in the future (seconds). */
    private const FUTURE_SKEW_SECONDS = 300;

    public const STATUS_VALID = 'valid';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_INVALID = 'invalid';

    public static function encode(int $clientId, ?int $issuedAt = null): string
    {
        $payload = $clientId.'.'.($issuedAt ?? now()->getTimestamp());

        return $payload.'.'.self::sign($payload);
    }

    /**
     * Client id for a valid, unexpired state; null otherwise.
     */
    public static function decode(?string $state): ?int
    {
        $result = self::inspect($state);

        return $result['status'] === self::STATUS_VALID ? $result['client_id'] : null;
    }

    /**
     * Distinguishes "genuine but expired" (tell the technician to generate a fresh
     * link) from "not ours" (say nothing about any client). client_id is only
     * returned for a valid state.
     *
     * @return array{status: string, client_id: ?int}
     */
    public static function inspect(?string $state): array
    {
        $invalid = ['status' => self::STATUS_INVALID, 'client_id' => null];

        if (! is_string($state) || substr_count($state, '.') !== 2) {
            return $invalid;
        }

        [$clientPart, $issuedPart, $signature] = explode('.', $state, 3);
        $clientId = (int) $clientPart;
        $issuedAt = (int) $issuedPart;

        if ($clientId <= 0 || (string) $clientId !== $clientPart
            || $issuedAt <= 0 || (string) $issuedAt !== $issuedPart
            || ! hash_equals(self::sign($clientPart.'.'.$issuedPart), $signature)) {
            return $invalid;
        }

        $now = now()->getTimestamp();

        if ($issuedAt > $now + self::FUTURE_SKEW_SECONDS) {
            return $invalid;
        }

        if ($now - $issuedAt > self::ttlSeconds()) {
            return ['status' => self::STATUS_EXPIRED, 'client_id' => null];
        }

        return ['status' => self::STATUS_VALID, 'client_id' => $clientId];
    }

    public static function ttlHours(): int
    {
        // Clamp so a typo cannot make links immortal (max 14 days) or unusable (min 1h).
        return max(1, min(336, (int) config('services.entra_sync.admin_consent_link_ttl_hours', 24)));
    }

    private static function ttlSeconds(): int
    {
        return self::ttlHours() * 3600;
    }

    private static function sign(string $payload): string
    {
        return hash_hmac('sha256', self::CONTEXT.$payload, (string) config('app.key'));
    }
}
