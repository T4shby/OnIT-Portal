<?php

namespace App\Support;

class AdminConsentState
{
    public static function encode(int $clientId): string
    {
        $payload = (string) $clientId;

        return $payload.'.'.hash_hmac('sha256', $payload, self::signingKey());
    }

    public static function decode(?string $state): ?int
    {
        if (! is_string($state) || $state === '') {
            return null;
        }

        if (str_contains($state, '.')) {
            [$payload, $signature] = explode('.', $state, 2);
            $clientId = (int) $payload;

            if ($clientId <= 0 || ! hash_equals(self::sign($payload), $signature)) {
                return null;
            }

            return $clientId;
        }

        if (str_starts_with($state, 'client-')) {
            $clientId = (int) substr($state, 7);

            return $clientId > 0 ? $clientId : null;
        }

        return null;
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
