<?php

namespace App\Services\Gdap;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Partner-tenant Graph calls for GDAP. Tokens are taken in the On IT tenant,
 * never in a customer tenant.
 */
class GdapGraphClient
{
    private const GRAPH = 'https://graph.microsoft.com/v1.0/tenantRelationships/delegatedAdminRelationships';

    public function isConfigured(): bool
    {
        return $this->partnerTenantId() !== null
            && filled(config('services.gdap.client_id'))
            && filled(config('services.gdap.client_secret'));
    }

    public function approvalUrl(string $relationshipId): string
    {
        return 'https://admin.microsoft.com/AdminPortal/Home#/partners/invitation/granularAdminRelationships/'
            .$relationshipId;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listRelationships(): array
    {
        $url = self::GRAPH.'?$top=100';
        $rows = [];
        $pages = 0;

        while ($url !== null && $pages < 20) {
            $pages++;
            $response = $this->send('GET', $url);
            foreach ($response->json('value') ?? [] as $row) {
                if (is_array($row)) {
                    $rows[] = $this->normalizeRelationship($row);
                }
            }
            $next = $response->json('@odata.nextLink');
            $url = is_string($next) && $next !== '' ? $next : null;
        }

        if ($url !== null) {
            throw new GdapGraphException('GDAP relationship list was longer than 20 pages. Refusing to continue so a truncated list cannot create duplicate requests.');
        }

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listAccessAssignments(string $relationshipId): array
    {
        $url = self::GRAPH.'/'.rawurlencode($relationshipId).'/accessAssignments?$top=100';
        $response = $this->send('GET', $url);
        $rows = [];
        foreach ($response->json('value') ?? [] as $row) {
            if (is_array($row)) {
                $rows[] = $this->normalizeAssignment($row);
            }
        }

        return $rows;
    }

    /**
     * @param  list<string>  $roleIds
     * @return array<string, mixed>
     */
    public function createRelationship(string $displayName, string $customerTenantId, string $customerName, array $roleIds): array
    {
        $response = $this->send('POST', self::GRAPH, [
            'displayName' => mb_substr($displayName, 0, 50),
            'duration' => (string) config('services.gdap.duration', 'P730D'),
            'customer' => [
                'tenantId' => $customerTenantId,
                'displayName' => mb_substr($customerName, 0, 80),
            ],
            'accessDetails' => [
                'unifiedRoles' => array_map(
                    fn (string $id): array => ['roleDefinitionId' => $id],
                    $roleIds,
                ),
            ],
            'autoExtendDuration' => (string) config('services.gdap.auto_extend_duration', 'P180D'),
        ]);

        $body = $response->json();

        return $this->normalizeRelationship(is_array($body) ? $body : []);
    }

    public function lockForApproval(string $relationshipId): void
    {
        $this->send('POST', self::GRAPH.'/'.rawurlencode($relationshipId).'/requests', [
            'action' => 'lockForApproval',
        ]);
    }

    public function enableAutoExtend(string $relationshipId, ?string $etag): void
    {
        $headers = [];
        if (filled($etag)) {
            $headers['If-Match'] = $etag;
        }

        $this->send(
            'PATCH',
            self::GRAPH.'/'.rawurlencode($relationshipId),
            ['autoExtendDuration' => (string) config('services.gdap.auto_extend_duration', 'P180D')],
            $headers,
        );
    }

    /**
     * @param  list<string>  $roleIds
     * @return array<string, mixed>
     */
    public function createAccessAssignment(string $relationshipId, string $groupId, array $roleIds): array
    {
        $response = $this->send('POST', self::GRAPH.'/'.rawurlencode($relationshipId).'/accessAssignments', [
            'accessContainer' => [
                'accessContainerId' => $groupId,
                'accessContainerType' => 'securityGroup',
            ],
            'accessDetails' => [
                'unifiedRoles' => array_map(
                    fn (string $id): array => ['roleDefinitionId' => $id],
                    $roleIds,
                ),
            ],
        ]);

        $body = $response->json();

        return $this->normalizeAssignment(is_array($body) ? $body : []);
    }

    /**
     * @param  list<string>  $roleIds
     */
    public function updateAccessAssignment(string $relationshipId, string $assignmentId, array $roleIds, ?string $etag): void
    {
        $headers = [];
        if (filled($etag)) {
            $headers['If-Match'] = $etag;
        }

        $this->send(
            'PATCH',
            self::GRAPH.'/'.rawurlencode($relationshipId).'/accessAssignments/'.rawurlencode($assignmentId),
            [
                'accessDetails' => [
                    'unifiedRoles' => array_map(
                        fn (string $id): array => ['roleDefinitionId' => $id],
                        $roleIds,
                    ),
                ],
            ],
            $headers,
        );
    }

    /**
     * Public directory lookup. Does not use the partner app.
     */
    public function resolveTenantIdFromDomain(string $domain): ?string
    {
        $domain = strtolower(trim($domain));
        if (! preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $domain)) {
            return null;
        }

        try {
            $response = Http::timeout(15)
                ->acceptJson()
                ->get('https://login.microsoftonline.com/'.rawurlencode($domain).'/v2.0/.well-known/openid-configuration');
        } catch (\Throwable) {
            return null;
        }

        if ($response->failed()) {
            return null;
        }

        $issuer = (string) $response->json('issuer');
        if (preg_match('#login\.microsoftonline\.com/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})#i', $issuer, $m)) {
            return strtolower($m[1]);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $headers
     */
    private function send(string $method, string $url, array $body = [], array $headers = []): Response
    {
        $response = $this->request($method, $url, $body, $headers, refreshToken: false);

        if ($response->status() === 401) {
            $response = $this->request($method, $url, $body, $headers, refreshToken: true);
        }

        if ($response->status() === 429) {
            $wait = (int) $response->header('Retry-After');
            sleep(max(1, min(5, $wait > 0 ? $wait : 2)));
            $response = $this->request($method, $url, $body, $headers, refreshToken: false);
        }

        if ($response->failed()) {
            $message = (string) ($response->json('error.message') ?: 'Graph returned '.$response->status());
            $message = trim(preg_replace('/\s+/', ' ', $message) ?? $message);

            throw new GdapGraphException(
                mb_substr($message, 0, 400),
                $response->status(),
            );
        }

        return $response;
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $headers
     */
    private function request(string $method, string $url, array $body, array $headers, bool $refreshToken): Response
    {
        $pending = Http::withToken($this->accessToken($refreshToken))
            ->acceptJson()
            ->timeout(30);

        if ($headers !== []) {
            $pending = $pending->withHeaders($headers);
        }

        return match ($method) {
            'POST' => $pending->post($url, $body),
            'PATCH' => $pending->patch($url, $body),
            default => $pending->get($url),
        };
    }

    private function accessToken(bool $refresh): string
    {
        $tenantId = $this->partnerTenantId();
        if ($tenantId === null) {
            throw new GdapGraphException('GDAP partner tenant id is not configured.');
        }

        $cacheKey = 'gdap_graph_token.'.$tenantId;
        if ($refresh) {
            Cache::forget($cacheKey);
        }

        return Cache::remember($cacheKey, now()->addMinutes(50), function () use ($tenantId) {
            $response = Http::asForm()->timeout(30)->post(
                "https://login.microsoftonline.com/{$tenantId}/oauth2/v2.0/token",
                [
                    'client_id' => config('services.gdap.client_id'),
                    'client_secret' => config('services.gdap.client_secret'),
                    'scope' => 'https://graph.microsoft.com/.default',
                    'grant_type' => 'client_credentials',
                ],
            );

            if ($response->failed() || ! filled($response->json('access_token'))) {
                throw new GdapGraphException(
                    'Partner-tenant Graph token was refused ('.$response->status().').',
                    $response->status(),
                );
            }

            return (string) $response->json('access_token');
        });
    }

    private function partnerTenantId(): ?string
    {
        $id = strtolower(trim((string) config('services.gdap.partner_tenant_id')));

        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $id) === 1
            ? $id
            : null;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function normalizeRelationship(array $row): array
    {
        $roles = [];
        foreach ($row['accessDetails']['unifiedRoles'] ?? [] as $role) {
            if (is_array($role) && filled($role['roleDefinitionId'] ?? null)) {
                $roles[] = strtolower((string) $role['roleDefinitionId']);
            }
        }

        $customer = is_array($row['customer'] ?? null) ? $row['customer'] : [];

        return [
            'id' => (string) ($row['id'] ?? ''),
            'etag' => isset($row['@odata.etag']) ? (string) $row['@odata.etag'] : null,
            'display_name' => (string) ($row['displayName'] ?? ''),
            'status' => (string) ($row['status'] ?? ''),
            'customer_tenant_id' => strtolower((string) ($customer['tenantId'] ?? '')),
            'role_ids' => $roles,
            'auto_extend' => (string) ($row['autoExtendDuration'] ?? ''),
            'end_at' => isset($row['endDateTime']) ? (string) $row['endDateTime'] : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function normalizeAssignment(array $row): array
    {
        $roles = [];
        foreach ($row['accessDetails']['unifiedRoles'] ?? [] as $role) {
            if (is_array($role) && filled($role['roleDefinitionId'] ?? null)) {
                $roles[] = strtolower((string) $role['roleDefinitionId']);
            }
        }

        $container = is_array($row['accessContainer'] ?? null) ? $row['accessContainer'] : [];

        return [
            'id' => (string) ($row['id'] ?? ''),
            'etag' => isset($row['@odata.etag']) ? (string) $row['@odata.etag'] : null,
            'status' => (string) ($row['status'] ?? ''),
            'group_id' => strtolower((string) ($container['accessContainerId'] ?? '')),
            'role_ids' => $roles,
        ];
    }
}
