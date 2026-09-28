<?php

namespace App\Services\Huntress;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Huntress REST API client (Basic auth).
 *
 * @see https://api.huntress.io/docs
 * @see https://support.huntress.io/hc/en-us/articles/4780697192851-Huntress-REST-API-Overview
 */
class HuntressApiClient
{
    private const BASE_URL = 'https://api.huntress.io/v1';

    public function isConfigured(): bool
    {
        return (bool) config('services.huntress.enabled')
            && filled(config('services.huntress.api_key'))
            && filled(config('services.huntress.api_secret'));
    }

    /**
     * GET a path under /v1 (leading slash optional).
     *
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function get(string $path, array $query = [], int $timeoutSeconds = 60): array
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Huntress API is not configured.');
        }

        $url = self::BASE_URL.'/'.ltrim($path, '/');

        $response = Http::withBasicAuth(
            (string) config('services.huntress.api_key'),
            (string) config('services.huntress.api_secret'),
        )
            ->timeout($timeoutSeconds)
            ->acceptJson()
            ->get($url, $query);

        if ($response->failed()) {
            // Truncated like SuperOpsApiClient: error bodies can echo incident / customer
            // detail and an unbounded body can flood single-file logging.
            Log::error('Huntress HTTP request failed', [
                'status' => $response->status(),
                'url' => $url,
                'body' => Str::limit($response->body(), 1000),
            ]);

            throw new RequestException($response);
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            throw new RuntimeException('Huntress API returned a non-JSON response.');
        }

        return $payload;
    }
}
