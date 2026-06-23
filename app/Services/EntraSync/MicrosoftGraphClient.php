<?php

namespace App\Services\EntraSync;

use App\Enums\EntraIdentityType;
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
     * Licensed member users and shared mailboxes in the customer tenant.
     *
     * @return list<array{
     *     id: string,
     *     mail: ?string,
     *     userPrincipalName: ?string,
     *     displayName: ?string,
     *     accountEnabled: bool,
     *     identityType: EntraIdentityType,
     * }>
     */
    public function listSyncEligibleUsers(string $tenantId): array
    {
        $eligible = [];

        foreach ($this->listTenantMemberUsers($tenantId) as $user) {
            $mailboxPurpose = $this->getMailboxUserPurpose($tenantId, $user['id']);
            $hasActiveLicense = $this->hasActiveLicense($tenantId, $user['id']);

            if ($mailboxPurpose === 'shared') {
                $eligible[] = array_merge($user, [
                    'identityType' => EntraIdentityType::SharedMailbox,
                ]);

                continue;
            }

            if ($hasActiveLicense) {
                $eligible[] = array_merge($user, [
                    'identityType' => EntraIdentityType::User,
                ]);
            }
        }

        return $eligible;
    }

    /**
     * @return list<array{id: string, mail: ?string, userPrincipalName: ?string, displayName: ?string, accountEnabled: bool}>
     */
    public function listTenantMemberUsers(string $tenantId): array
    {
        $users = [];
        $url = 'https://graph.microsoft.com/v1.0/users';
        $query = [
            '$filter' => "userType eq 'Member'",
            '$select' => 'id,mail,userPrincipalName,displayName,accountEnabled',
            '$top' => 999,
        ];

        while ($url) {
            $response = $this->request($tenantId)
                ->get($url, $url === 'https://graph.microsoft.com/v1.0/users' ? $query : []);

            if ($response->failed()) {
                throw new RuntimeException(
                    'Microsoft Graph request failed: '.$response->status().' '.$response->body()
                );
            }

            $data = $response->json();

            foreach ($data['value'] ?? [] as $user) {
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

    public function listTenantGroups(string $tenantId): array
    {
        $groups = [];
        $url = 'https://graph.microsoft.com/v1.0/groups';
        $query = [
            '$select' => 'id,displayName,mail,mailEnabled,securityEnabled,groupTypes,description',
            '$top' => 999,
        ];

        while ($url) {
            $response = $this->request($tenantId)
                ->get($url, $url === 'https://graph.microsoft.com/v1.0/groups' ? $query : []);

            if ($response->failed()) {
                throw new RuntimeException(
                    'Microsoft Graph groups request failed: '.$response->status().' '.$response->body()
                );
            }

            $data = $response->json();

            foreach ($data['value'] ?? [] as $group) {
                $groups[] = [
                    'id' => (string) $group['id'],
                    'displayName' => $group['displayName'] ?? null,
                    'mail' => $group['mail'] ?? null,
                    'mailEnabled' => (bool) ($group['mailEnabled'] ?? false),
                    'securityEnabled' => (bool) ($group['securityEnabled'] ?? false),
                    'groupTypes' => $group['groupTypes'] ?? [],
                    'description' => $group['description'] ?? null,
                ];
            }

            $url = $data['@odata.nextLink'] ?? null;
        }

        return $groups;
    }

    /**
     * @return list<string>
     */
    public function getUserLicenseSkuPartNumbers(string $tenantId, string $userId): array
    {
        $response = $this->request($tenantId)
            ->get("https://graph.microsoft.com/v1.0/users/{$userId}/licenseDetails");

        if ($response->failed()) {
            throw new RuntimeException(
                'Microsoft Graph licenseDetails failed for user '.$userId.': '.$response->status().' '.$response->body()
            );
        }

        $skus = [];

        foreach ($response->json('value') ?? [] as $license) {
            if (! empty($license['skuPartNumber'])) {
                $skus[] = (string) $license['skuPartNumber'];
            }
        }

        return $skus;
    }

    public function hasActiveLicense(string $tenantId, string $userId): bool
    {
        return $this->getUserLicenseSkuPartNumbers($tenantId, $userId) !== [];
    }

    public function getMailboxUserPurpose(string $tenantId, string $userId): ?string
    {
        $response = $this->request($tenantId)
            ->get("https://graph.microsoft.com/v1.0/users/{$userId}/mailboxSettings", [
                '$select' => 'userPurpose',
            ]);

        if ($response->status() === 404) {
            return null;
        }

        if ($response->failed()) {
            throw new RuntimeException(
                'Microsoft Graph mailboxSettings failed for user '.$userId.': '.$response->status().' '.$response->body()
            );
        }

        $purpose = $response->json('userPurpose');

        return is_string($purpose) ? strtolower($purpose) : null;
    }

    /**
     * @deprecated Use listSyncEligibleUsers() — group scope retained for SCIM reference only.
     *
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

    /**
     * @return list<string>
     */
    public function listGroupMemberUserIds(string $tenantId, string $groupId): array
    {
        $ids = [];
        $url = "https://graph.microsoft.com/v1.0/groups/{$groupId}/members/microsoft.graph.user";
        $query = ['$select' => 'id', '$top' => 999];

        while ($url) {
            $response = $this->request($tenantId)
                ->get($url, $url === "https://graph.microsoft.com/v1.0/groups/{$groupId}/members/microsoft.graph.user" ? $query : []);

            if ($response->failed()) {
                throw new RuntimeException(
                    'Microsoft Graph group members request failed: '.$response->status().' '.$response->body()
                );
            }

            $data = $response->json();

            foreach ($data['value'] ?? [] as $member) {
                if (! empty($member['id'])) {
                    $ids[] = (string) $member['id'];
                }
            }

            $url = $data['@odata.nextLink'] ?? null;
        }

        return $ids;
    }

    public function addGroupMember(string $tenantId, string $groupId, string $userId): void
    {
        $response = $this->request($tenantId)
            ->post("https://graph.microsoft.com/v1.0/groups/{$groupId}/members/\$ref", [
                '@odata.id' => "https://graph.microsoft.com/v1.0/directoryObjects/{$userId}",
            ]);

        if ($response->status() === 204 || $response->status() === 201) {
            return;
        }

        if ($response->status() === 400 && str_contains($response->body(), 'already exist')) {
            return;
        }

        throw new RuntimeException(
            'Microsoft Graph add group member failed: '.$response->status().' '.$response->body()
        );
    }

    public function removeGroupMember(string $tenantId, string $groupId, string $userId): void
    {
        $response = $this->request($tenantId)
            ->delete("https://graph.microsoft.com/v1.0/groups/{$groupId}/members/{$userId}/\$ref");

        if ($response->status() === 204 || $response->status() === 404) {
            return;
        }

        throw new RuntimeException(
            'Microsoft Graph remove group member failed: '.$response->status().' '.$response->body()
        );
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
