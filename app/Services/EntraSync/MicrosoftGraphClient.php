<?php

namespace App\Services\EntraSync;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class MicrosoftGraphClient
{
    public function isConfigured(): bool
    {
        return filled(config('services.entra_sync.client_id'))
            && filled(config('services.entra_sync.client_secret'));
    }

    /**
     * @return list<array{id: string, mail: ?string, userPrincipalName: ?string, displayName: ?string, accountEnabled: bool}>
     */
    public function listGroupUsers(string $tenantId, string $groupId): array
    {
        $users = [];
        $url = "https://graph.microsoft.com/v1.0/groups/{$groupId}/transitiveMembers/microsoft.graph.user";
        $query = [
            '$select' => 'id,mail,userPrincipalName,displayName,accountEnabled',
            '$top' => 999,
        ];

        while ($url) {
            $response = $this->request($tenantId)
                ->get($url, $url === "https://graph.microsoft.com/v1.0/groups/{$groupId}/transitiveMembers/microsoft.graph.user" ? $query : []);

            if ($response->failed()) {
                throw new RuntimeException(
                    'Microsoft Graph request failed: '.$response->status().' '.$response->body()
                );
            }

            $data = $response->json();

            foreach ($data['value'] ?? [] as $user) {
                if (($user['@odata.type'] ?? '') !== '' && ! Str::contains($user['@odata.type'] ?? '', 'user')) {
                    continue;
                }

                $users[] = [
                    'id' => (string) $user['id'],
                    'mail' => $user['mail'] ?? null,
                    'userPrincipalName' => $user['userPrincipalName'] ?? null,
                    'displayName' => $user['displayName'] ?? null,
                    'accountEnabled' => (bool) ($user['accountEnabled'] ?? true),
                ];
            }

            $url = $data['@odata.nextLink'] ?? null;
        }

        return $users;
    }

    private function request(string $tenantId): PendingRequest
    {
        return Http::acceptJson()
            ->withToken($this->accessToken($tenantId))
            ->timeout(30);
    }

    private function accessToken(string $tenantId): string
    {
        return Cache::remember(
            'entra_graph_token.'.$tenantId,
            now()->addMinutes(50),
            function () use ($tenantId) {
                $response = Http::asForm()->post(
                    "https://login.microsoftonline.com/{$tenantId}/oauth2/v2.0/token",
                    [
                        'client_id' => config('services.entra_sync.client_id'),
                        'client_secret' => config('services.entra_sync.client_secret'),
                        'scope' => 'https://graph.microsoft.com/.default',
                        'grant_type' => 'client_credentials',
                    ]
                );

                if ($response->failed()) {
                    throw new RuntimeException(
                        'Failed to obtain Graph token for tenant '.$tenantId.': '.$response->body()
                    );
                }

                return $response->json('access_token');
            }
        );
    }
}
