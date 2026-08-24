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

        $clientErrors = $this->collectClientErrors($payload);

        if (! empty($payload['errors']) || $clientErrors !== []) {
            $message = $this->formatFailureMessage($payload, $clientErrors);

            Log::error('SuperOps GraphQL errors', [
                'errors' => $payload['errors'] ?? [],
                'client_errors' => $clientErrors,
            ]);

            throw new RuntimeException('SuperOps API error: '.$message);
        }

        return $payload['data'] ?? [];
    }

    /**
     * SuperOps often returns HTTP 200 with empty GraphQL `message` and the real
     * reason in `extensions.clientError` (e.g. mandatory_validation_failed).
     *
     * @return list<array<string, mixed>>
     */
    private function collectClientErrors(array $payload): array
    {
        $found = [];

        foreach ([$payload['extensions'] ?? null, $payload['data']['extensions'] ?? null] as $extensions) {
            if (is_array($extensions) && isset($extensions['clientError']) && is_array($extensions['clientError'])) {
                foreach ($extensions['clientError'] as $error) {
                    if (is_array($error)) {
                        $found[] = $error;
                    }
                }
            }
        }

        foreach ($payload['errors'] ?? [] as $error) {
            if (! is_array($error)) {
                continue;
            }

            $nested = $error['extensions']['clientError'] ?? null;
            if (is_array($nested)) {
                foreach ($nested as $item) {
                    if (is_array($item)) {
                        $found[] = $item;
                    }
                }
            }
        }

        return $found;
    }

    /**
     * @param  list<array<string, mixed>>  $clientErrors
     */
    private function formatFailureMessage(array $payload, array $clientErrors): string
    {
        $messages = collect($payload['errors'] ?? [])->pluck('message')->filter()->implode('; ');

        if ($clientErrors !== []) {
            $encoded = json_encode($clientErrors);
            $messages = $messages === ''
                ? 'clientError '.$encoded
                : $messages.'; clientError '.$encoded;
        }

        return $messages !== '' ? $messages : 'unknown SuperOps error';
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
