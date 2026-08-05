<?php

namespace App\Services\Dropsuite;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * NinjaOne SaaS Backup (Dropsuite) partner GET client.
 *
 * Sends both common reseller auth patterns:
 * - X-Access-Token + X-Reseller-Token (MSPbots / common reseller docs)
 * - Authorization: Token … (legacy/Django Token style)
 *
 * @see https://help.dropsuite.com/hc/en-us/articles/20422080552855-15-API-Settings
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
    public function get(string $path, array $query = [], int $timeoutSeconds = 30): array
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Dropsuite API is not configured.');
        }

        $token = $this->authToken();
        $response = Http::withHeaders([
            'Authorization' => 'Token '.$token,
            'X-Access-Token' => $token,
            'X-Reseller-Token' => $this->resellerToken(),
            'Accept' => 'application/json',
        ])
            ->timeout($timeoutSeconds)
            ->get($this->url($path), $query);

        if ($response->failed()) {
            Log::warning('Dropsuite HTTP request failed', [
                'status' => $response->status(),
                'path' => $path,
                'body' => $response->body(),
            ]);

            throw new RequestException($response);
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            throw new RuntimeException('Dropsuite API returned a non-JSON response.');
        }

        return $payload;
    }

    private function apiUrl(): string
    {
        return rtrim((string) config('services.dropsuite.api_url', 'https://dropsuite.us/api'), '/');
    }

    private function resellerToken(): string
    {
        return trim((string) config('services.dropsuite.reseller_token'));
    }

    private function authToken(): string
    {
        $token = trim((string) config('services.dropsuite.auth_token'));

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
