<?php

namespace App\Services\SuperOps;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** @see Brain/SuperOpsIntegration.md */
class SuperOpsApiClient
{
    public function isConfigured(): bool
    {
        return filled(config('services.superops.api_token'))
            && filled(config('services.superops.subdomain'));
    }

    public function query(string $query, array $variables = []): array
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('SuperOps API is not configured.');
        }

        $response = Http::withHeaders([
            'Authorization' => 'Bearer '.config('services.superops.api_token'),
            'CustomerSubDomain' => config('services.superops.subdomain'),
        ])
            ->timeout(30)
            ->post($this->endpoint(), [
                'query' => $query,
                'variables' => $variables,
            ]);

        if ($response->failed()) {
            throw new RequestException($response);
        }

        $payload = $response->json();

        if (! empty($payload['errors'])) {
            throw new RuntimeException(
                'SuperOps API error: '.collect($payload['errors'])->pluck('message')->implode('; ')
            );
        }

        return $payload['data'] ?? [];
    }

    private function endpoint(): string
    {
        return config('services.superops.region', 'us') === 'eu'
            ? 'https://euapi.superops.ai/msp'
            : 'https://api.superops.ai/msp';
    }
}
