<?php

namespace App\Services\SuperOps;

use App\Models\Client;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class SuperOpsUserSyncService
{
    public function __construct(private SuperOpsApiClient $api) {}

    /**
     * Approximate SuperOps requester count for this SuperOps client (cached briefly).
     * Used by onboarding to flag when SuperOps bulk already exists outside Entra scope.
     */
    public function countClientRequesters(Client $client): ?int
    {
        $accountId = trim((string) $client->superops_account_id);
        if ($accountId === '' || ! $this->api->isConfigured()) {
            return null;
        }

        $cacheKey = 'superops.requester_count.'.$client->id;

        return Cache::remember($cacheKey, now()->addMinutes(10), function () use ($accountId, $client): ?int {
            try {
                $page = 1;
                $pageSize = 100;
                $total = 0;
                $maxPages = 25;

                do {
                    $data = $this->api->query(<<<'GQL'
                        query getClientUserList($input: GetClientUserListInput!) {
                            getClientUserList(input: $input) {
                                userList { userId }
                                listInfo { totalCount hasMore }
                            }
                        }
                    GQL, [
                        'input' => [
                            'page' => $page,
                            'pageSize' => $pageSize,
                            'condition' => [
                                'attribute' => 'client.accountId',
                                'operator' => 'is',
                                'value' => $accountId,
                            ],
                        ],
                    ]);

                    $payload = $data['getClientUserList'] ?? [];
                    if (isset($payload['listInfo']['totalCount'])) {
                        return max(0, (int) $payload['listInfo']['totalCount']);
                    }

                    $list = $payload['userList'] ?? [];
                    if (! is_array($list)) {
                        break;
                    }

                    $batch = count($list);
                    $total += $batch;
                    $hasMore = (bool) ($payload['listInfo']['hasMore'] ?? ($batch === $pageSize));
                    $page++;
                } while ($hasMore && $page <= $maxPages);

                return $total;
            } catch (\Throwable $e) {
                Log::warning('SuperOps requester count failed', [
                    'client_id' => $client->id,
                    'error' => $e->getMessage(),
                ]);

                return null;
            }
        });
    }

    /**
     * Push SuperOps first/last names by email for requesters already on this SuperOps client.
     * Used when Graph cannot write extensionAttribute1 (hybrid / on-prem mastered users).
     *
     * @param  array<string, array{firstName: string, lastName: string}>  $byEmail lowercase email keys
     * @return int number of successful updateClientUser calls
     */
    public function pushRequesterNames(Client $client, array $byEmail): int
    {
        $accountId = trim((string) $client->superops_account_id);
        if ($accountId === '' || $byEmail === [] || ! $this->api->isConfigured()) {
            return 0;
        }

        $wanted = [];
        foreach ($byEmail as $email => $names) {
            $key = strtolower(trim((string) $email));
            if ($key === '' || ! is_array($names)) {
                continue;
            }
            $first = trim((string) ($names['firstName'] ?? ''));
            $last = trim((string) ($names['lastName'] ?? ''));
            if ($last === '') {
                continue;
            }
            $wanted[$key] = [
                'firstName' => $first !== '' ? $first : 'User',
                'lastName' => $last,
            ];
        }

        if ($wanted === []) {
            return 0;
        }

        $emailToUserId = $this->mapRequesterEmailsToUserIds($accountId, array_keys($wanted));
        $updated = 0;

        foreach ($wanted as $email => $names) {
            $userId = $emailToUserId[$email] ?? null;
            if ($userId === null || $userId === '') {
                continue;
            }

            try {
                $this->api->query(<<<'GQL'
                    mutation updateClientUser($input: UpdateClientUserInput!) {
                        updateClientUser(input: $input) {
                            userId
                            firstName
                            lastName
                            name
                        }
                    }
                GQL, [
                    'input' => [
                        'userId' => (string) $userId,
                        'firstName' => $names['firstName'],
                        'lastName' => $names['lastName'],
                    ],
                ]);
                $updated++;
            } catch (\Throwable $e) {
                Log::warning('SuperOps requester name update failed', [
                    'client_id' => $client->id,
                    'email' => $email,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($updated > 0) {
            Cache::forget('superops.requester_count.'.$client->id);
        }

        return $updated;
    }

    /**
     * @param  list<string>  $emails lower-cased
     * @return array<string, string> email => userId
     */
    private function mapRequesterEmailsToUserIds(string $accountId, array $emails): array
    {
        $needed = array_fill_keys($emails, true);
        $found = [];
        $page = 1;
        $pageSize = 100;
        $maxPages = 30;

        do {
            try {
                $data = $this->api->query(<<<'GQL'
                    query getClientUserList($input: GetClientUserListInput!) {
                        getClientUserList(input: $input) {
                            userList { userId email }
                            listInfo { hasMore }
                        }
                    }
                GQL, [
                    'input' => [
                        'page' => $page,
                        'pageSize' => $pageSize,
                        'condition' => [
                            'attribute' => 'client.accountId',
                            'operator' => 'is',
                            'value' => $accountId,
                        ],
                    ],
                ]);
            } catch (\Throwable $e) {
                Log::warning('SuperOps requester list for name push failed', [
                    'account_id' => $accountId,
                    'error' => $e->getMessage(),
                ]);

                break;
            }

            $payload = $data['getClientUserList'] ?? [];
            $list = $payload['userList'] ?? [];
            if (! is_array($list) || $list === []) {
                break;
            }

            foreach ($list as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $email = strtolower(trim((string) ($row['email'] ?? '')));
                $userId = (string) ($row['userId'] ?? '');
                if ($email !== '' && $userId !== '' && isset($needed[$email])) {
                    $found[$email] = $userId;
                    unset($needed[$email]);
                }
            }

            if ($needed === []) {
                break;
            }

            $hasMore = (bool) ($payload['listInfo']['hasMore'] ?? (count($list) === $pageSize));
            $page++;
        } while ($hasMore && $page <= $maxPages);

        return $found;
    }

    public function syncUser(User $user): bool
    {
        if (! $this->api->isConfigured()) {
            return false;
        }

        try {
            $data = $this->api->query(<<<'GQL'
                query getClientUserList($input: GetClientUserListInput!) {
                    getClientUserList(input: $input) {
                        userList { userId email client }
                    }
                }
            GQL, [
                'input' => [
                    'page' => 1,
                    'pageSize' => 1,
                    'condition' => [
                        'field' => 'email',
                        'operator' => 'eq',
                        'value' => $user->email,
                    ],
                ],
            ]);

            $match = $data['getClientUserList']['userList'][0] ?? null;

            if (! $match) {
                return false;
            }

            $clientBlob = $match['client'] ?? null;
            if (is_string($clientBlob) && $clientBlob !== '') {
                $decoded = json_decode($clientBlob, true);
                $clientBlob = is_array($decoded) ? $decoded : [];
            }
            if (! is_array($clientBlob)) {
                $clientBlob = [];
            }

            $user->update([
                'superops_user_id' => (string) $match['userId'],
                'superops_synced_at' => now(),
            ]);

            if ($user->client && empty($user->client->superops_account_id) && ! empty($clientBlob['accountId'])) {
                $user->client->update(['superops_account_id' => (string) $clientBlob['accountId']]);
            }

            return true;
        } catch (\Throwable $e) {
            Log::warning('SuperOps user sync failed', ['email' => $user->email, 'error' => $e->getMessage()]);

            return false;
        }
    }
}
