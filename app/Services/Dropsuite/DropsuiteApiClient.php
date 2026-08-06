<?php

namespace App\Services\Dropsuite;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * NinjaOne SaaS Backup (Dropsuite) sub-reseller GET client.
 *
 * PDF (REST API for subreseller v1.00) requires:
 * - X-Reseller-Token
 * - X-Access-Token
 *
 * Also sends Authorization: Token … for older gateways that still expect it.
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
        return rtrim((string) config('services.dropsuite.api_url', 'https://dropsuite.uk/api'), '/');
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
