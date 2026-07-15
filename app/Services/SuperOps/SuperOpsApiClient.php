<?php

namespace App\Services\SuperOps;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * SuperOps MSP GraphQL client.
 *
 * Auth (developer.superops.com + working Python references):
 * - POST https://api.superops.ai/msp (or euapi for EU)
 * - Authorization: Bearer <API token>
 * - CustomerSubDomain: <MSP subdomain>
 * - Content-Type: application/json
 * - Body: { "query": "...", "variables": { ... } }
 *
 * @see https://developer.superops.com/msp
 * @see Brain/SuperOpsIntegration.md
 */
class SuperOpsApiClient
{
    public function isConfigured(): bool
    {
        return filled($this->apiToken())
            && filled(config('services.superops.subdomain'));
    }

    public function query(string $query, array $variables = [], int $timeoutSeconds = 60): array
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('SuperOps API is not configured.');
        }

        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer '.$this->apiToken(),
            'CustomerSubDomain' => (string) config('services.superops.subdomain'),
        ])
            ->timeout($timeoutSeconds)
            ->acceptJson()
            ->post($this->endpoint(), [
                'query' => $query,
                'variables' => $variables,
            ]);

        if ($response->failed()) {
            Log::error('SuperOps HTTP request failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new RequestException($response);
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            throw new RuntimeException('SuperOps API returned a non-JSON response.');
        }

        if (! empty($payload['errors'])) {
            $messages = collect($payload['errors'])->pluck('message')->filter()->implode('; ');

            Log::error('SuperOps GraphQL errors', [
                'errors' => $payload['errors'],
            ]);

            throw new RuntimeException('SuperOps API error: '.$messages);
        }

        return $payload['data'] ?? [];
    }

    private function apiToken(): string
    {
        $token = trim((string) config('services.superops.api_token'));

        // Allow either raw token or a pasted "Bearer <token>" value in .env.
        if (str_starts_with(strtolower($token), 'bearer ')) {
            $token = trim(substr($token, 7));
        }

        return $token;
    }

    private function endpoint(): string
    {
        return config('services.superops.region', 'us') === 'eu'
            ? 'https://euapi.superops.ai/msp'
            : 'https://api.superops.ai/msp';
    }
}
