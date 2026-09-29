<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * OAuth `state` (login-CSRF protection) for Microsoft sign-in, kept in a dedicated
 * short-lived cookie instead of the PHP session.
 *
 * Why not Socialite's built-in state: stateful Socialite stores the nonce in the
 * server-side session, and sign-in used to fail in production with "session lost"
 * (InvalidStateException), so the app ran Socialite stateless - which removed the
 * state check entirely and allowed login CSRF (pass 7, L11). Here the nonce lives
 * only in a cookie set on the redirect to Microsoft and read back on the callback,
 * the same pattern Auth.js and most OAuth middleware use. It does not depend on the
 * session row, the session driver, or concurrent requests rewriting the session;
 * it only needs a first-party cookie to survive a top-level GET redirect, which is
 * exactly what SameSite=Lax cookies (like the session cookie itself) do.
 *
 * - Value: JSON list of up to MAX_PENDING {s: state, t: issued-at} entries, so
 *   signing in from two tabs at once does not break the first tab.
 * - Tamper-proofing/confidentiality: Laravel's EncryptCookies (web group) encrypts
 *   and MACs the value, bound to the cookie name. Nothing is hand-signed here.
 * - Attributes: HttpOnly, SameSite=Lax, Path=/, host-only; Secure plus the
 *   `__Host-` name prefix whenever the app runs over HTTPS, so a sibling subdomain
 *   cannot plant ("toss") its own state cookie.
 * - Expiry is enforced server-side from the issued-at time, not just by Max-Age.
 * - One use: a matched entry is removed on the callback.
 */
class MicrosoftOAuthState
{
    public const TTL_MINUTES = 15;

    public const VALID = 'valid';

    public const MISSING = 'missing_cookie';

    public const MISMATCH = 'mismatch';

    public const EXPIRED = 'expired';

    private const MAX_PENDING = 5;

    private const BASE_NAME = 'onit_oauth_state';

    public static function cookieName(Request $request): string
    {
        return self::nameFor(self::secure($request));
    }

    public static function nameFor(bool $secure): string
    {
        return $secure ? '__Host-'.self::BASE_NAME : self::BASE_NAME;
    }

    /**
     * New random state plus the cookie that remembers it for this browser.
     *
     * @return array{0: string, 1: Cookie}
     */
    public static function issue(Request $request): array
    {
        $state = Str::random(40);

        $pending = array_values(array_filter(
            self::entries($request) ?? [],
            fn (array $entry) => ! self::isExpired($entry),
        ));
        $pending[] = ['s' => $state, 't' => now()->getTimestamp()];

        return [$state, self::cookie($request, array_slice($pending, -self::MAX_PENDING))];
    }

    /**
     * Check the callback's `state` against this browser's cookie. Always returns the
     * cookie to send back (matched entry and expired entries removed, or deleted).
     *
     * @return array{0: string, 1: Cookie}
     */
    public static function verify(Request $request, mixed $state): array
    {
        $entries = self::entries($request);

        if ($entries === null || $entries === []) {
            return [self::MISSING, self::forget($request)];
        }

        $matched = null;
        foreach ($entries as $index => $entry) {
            if (is_string($state) && $state !== '' && hash_equals($entry['s'], $state)) {
                $matched = $index;
            }
        }

        $result = match (true) {
            $matched === null => self::MISMATCH,
            self::isExpired($entries[$matched]) => self::EXPIRED,
            default => self::VALID,
        };

        $remaining = [];
        foreach ($entries as $index => $entry) {
            if ($index !== $matched && ! self::isExpired($entry)) {
                $remaining[] = $entry;
            }
        }

        return [$result, $remaining === [] ? self::forget($request) : self::cookie($request, $remaining)];
    }

    /**
     * @return list<array{s: string, t: int}>|null null when there is no usable cookie
     */
    private static function entries(Request $request): ?array
    {
        $raw = $request->cookie(self::cookieName($request));

        if (! is_string($raw) || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        if (! is_array($decoded)) {
            return null;
        }

        $entries = [];
        foreach ($decoded as $entry) {
            if (is_array($entry) && is_string($entry['s'] ?? null) && $entry['s'] !== '' && is_int($entry['t'] ?? null)) {
                $entries[] = ['s' => $entry['s'], 't' => $entry['t']];
            }
        }

        return $entries;
    }

    /**
     * @param  array{s: string, t: int}  $entry
     */
    private static function isExpired(array $entry): bool
    {
        $age = now()->getTimestamp() - $entry['t'];

        return $age < 0 || $age > self::TTL_MINUTES * 60;
    }

    /**
     * @param  list<array{s: string, t: int}>  $entries
     */
    private static function cookie(Request $request, array $entries): Cookie
    {
        return self::make($request, (string) json_encode($entries), now()->addMinutes(self::TTL_MINUTES)->getTimestamp());
    }

    private static function forget(Request $request): Cookie
    {
        return self::make($request, '', now()->subYear()->getTimestamp());
    }

    /**
     * Built directly rather than through the CookieJar, whose defaults come from the
     * session config: a SESSION_DOMAIN there would add a Domain attribute, which
     * browsers reject on a `__Host-` cookie (and which would widen it to subdomains).
     * The deletion also carries Secure, or browsers ignore it for a `__Host-` name.
     */
    private static function make(Request $request, string $value, int $expiresAt): Cookie
    {
        return Cookie::create(
            self::cookieName($request),
            $value,
            $expiresAt,
            '/',
            null,
            self::secure($request),
            true,
            false,
            Cookie::SAMESITE_LAX,
        );
    }

    private static function secure(Request $request): bool
    {
        return (bool) config('session.secure') || $request->isSecure();
    }
}
