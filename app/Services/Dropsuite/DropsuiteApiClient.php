<?php

namespace App\Services\Dropsuite;

use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * NinjaOne SaaS Backup (Dropsuite) UK/EU partner API.
 *
 * Headers:
 * - X-Reseller-Token = Reseller Token (UUID from API Information)
 * - X-Access-Token + Authorization: Token … = Authentication Token (Admin) or a
 *   per-user authentication_token from GET /users (required for GET /accounts)
 *
 * @see Brain/ClientAdminDashboard.md (Dropsuite)
 */
class DropsuiteApiClient
{
    public function isConfigured(): bool
    {
        return (bool) config('services.dropsuite.enabled')
            && filled($this->apiUrl())
            && filled($this->resellerToken())
            && filled($this->authToken());
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function get(
        string $path,
        array $query = [],
        int $timeoutSeconds = 30,
        ?string $accessToken = null,
        bool $quietNotFound = false,
    ): array {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Dropsuite API is not configured.');
        }

        $token = $this->normalizeToken($accessToken ?? $this->authToken());
        $response = Http::withHeaders([
            'Authorization' => 'Token '.$token,
            'X-Access-Token' => $token,
            'X-Reseller-Token' => $this->resellerToken(),
            'Accept' => 'application/json',
            'User-Agent' => 'OnIT-Portal/1.0',
        ])
            ->timeout($timeoutSeconds)
            ->get($this->url($path), $query);

        if ($response->failed()) {
            // Optional SharePoint/sites calls 404 on mailbox-only organisations.
            // The caller treats that as empty. Do not warn on every refresh.
            if (! ($quietNotFound && $response->status() === 404)) {
                Log::warning('Dropsuite HTTP request failed', [
                    'status' => $response->status(),
                    'path' => $path,
                    'body' => Str::limit($response->body(), 1000),
                ]);
            }

            throw new RequestException($response);
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            throw new RuntimeException('Dropsuite API returned a non-JSON response.');
        }

        return $payload;
    }

    /**
     * Same GET, one request per access token, in parallel.
     * A failed call is null for that key. Tokens are not logged.
     *
     * @param  array<string, string>  $accessTokenByKey
     * @param  array<string, mixed>  $query
     * @return array<string, array<string, mixed>|null>
     */
    public function getMany(
        string $path,
        array $accessTokenByKey,
        array $query = [],
        int $timeoutSeconds = 20,
    ): array {
        if (! $this->isConfigured() || $accessTokenByKey === []) {
            return [];
        }

        $url = $this->url($path);
        $reseller = $this->resellerToken();

        $responses = Http::pool(function (Pool $pool) use ($accessTokenByKey, $url, $query, $timeoutSeconds, $reseller) {
            foreach ($accessTokenByKey as $key => $accessToken) {
                $token = $this->normalizeToken($accessToken);
                $pool->as((string) $key)
                    ->withHeaders([
                        'Authorization' => 'Token '.$token,
                        'X-Access-Token' => $token,
                        'X-Reseller-Token' => $reseller,
                        'Accept' => 'application/json',
                        'User-Agent' => 'OnIT-Portal/1.0',
                    ])
                    ->timeout($timeoutSeconds)
                    ->get($url, $query);
            }
        });

        $out = [];
        foreach ($accessTokenByKey as $key => $accessToken) {
            $response = $responses[(string) $key] ?? null;
            if (! $response instanceof Response || $response->failed()) {
                $out[(string) $key] = null;
                continue;
            }
            $payload = $response->json();
            $out[(string) $key] = is_array($payload) ? $payload : null;
        }

        return $out;
    }

    private function apiUrl(): string
    {
        return rtrim((string) config('services.dropsuite.api_url', 'https://dropsuite.uk/api'), '/');
    }

    private function resellerToken(): string
    {
        return trim((string) config('services.dropsuite.reseller_token'));
    }

    private function authToken(): string
    {
        return $this->normalizeToken((string) config('services.dropsuite.auth_token'));
    }

    private function normalizeToken(string $token): string
    {
        $token = trim($token);

        if (str_starts_with(strtolower($token), 'token ')) {
            return trim(substr($token, 6));
        }

        if (str_starts_with(strtolower($token), 'bearer ')) {
            return trim(substr($token, 7));
        }

        return $token;
    }

    private function url(string $path): string
    {
        return $this->apiUrl().'/'.ltrim($path, '/');
    }
}
