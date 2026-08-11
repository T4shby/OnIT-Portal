<?php

namespace App\Services\SuperOps;

use App\Enums\UserProvisionSource;
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
                            'clientId' => $accountId,
                            'listInfo' => [
                                'page' => $page,
                                'pageSize' => $pageSize,
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
     * Push M365-primary emails onto SuperOps requesters + bind portal.superops_user_id whenever matched.
     * Match: SuperOps userId → primary/aliases (Graph proxyAddresses) → unique local-part.
     * Does not create requesters (SCIM does that).
     *
     * @param  list<array{
     *   to: string,
     *   from?: ?string,
     *   aliases?: list<string>,
     *   superops_user_id?: ?string,
     *   portal_user_id?: ?int,
     *   label?: ?string
     * }>  $renames  empty → active Entra-synced portal users for this client
     * @return array{updated: int, bound: int, unmatched: list<string>}
     */
    public function alignRequesterPrimaryEmails(Client $client, array $renames = []): array
    {
        $empty = ['updated' => 0, 'bound' => 0, 'unmatched' => []];
        $accountId = trim((string) $client->superops_account_id);
        if ($accountId === '' || ! $this->api->isConfigured()) {
            return $empty;
        }

        if ($renames === []) {
            $renames = User::query()
                ->where('client_id', $client->id)
                ->where('is_active', true)
                ->where('provisioned_by', UserProvisionSource::EntraSync)
                ->whereNotNull('email')
                ->get()
                ->map(static fn (User $u): array => [
                    'to' => strtolower((string) $u->email),
                    'from' => null,
                    'aliases' => [],
                    'superops_user_id' => filled($u->superops_user_id) ? (string) $u->superops_user_id : null,
                    'portal_user_id' => (int) $u->id,
                    'label' => trim($u->name.' <'.$u->email.'>'),
                ])
                ->filter(static fn (array $r): bool => $r['to'] !== '')
                ->values()
                ->all();
        }

        if ($renames === []) {
            return $empty;
        }

        $requesters = $this->listClientRequesterEmails($accountId);
        if ($requesters === []) {
            return [
                'updated' => 0,
                'bound' => 0,
                'unmatched' => array_values(array_filter(array_map(
                    static fn (array $r): string => (string) ($r['label'] ?? $r['to'] ?? ''),
                    $renames,
                ))),
            ];
        }

        $byUserId = [];
        $byEmail = [];
        $byLocal = [];
        foreach ($requesters as $row) {
            $uid = $row['userId'];
            $email = $row['email'];
            $byUserId[$uid] = $email;
            $byEmail[$email] = $uid;
            $local = (string) str($email)->before('@');
            if ($local !== '') {
                $byLocal[$local][] = $uid;
            }
        }

        $updated = 0;
        $bound = 0;
        $unmatched = [];
        $claimedTargets = [];

        foreach ($renames as $rename) {
            $to = strtolower(trim((string) ($rename['to'] ?? '')));
            if ($to === '' || ! filter_var($to, FILTER_VALIDATE_EMAIL)) {
                continue;
            }

            $label = trim((string) ($rename['label'] ?? $to));
            $from = isset($rename['from']) ? strtolower(trim((string) $rename['from'])) : null;
            $aliases = [];
            foreach ($rename['aliases'] ?? [] as $alias) {
                $alias = strtolower(trim((string) $alias));
                if ($alias !== '' && filter_var($alias, FILTER_VALIDATE_EMAIL)) {
                    $aliases[$alias] = true;
                }
            }
            if ($from !== null && $from !== '') {
                $aliases[$from] = true;
            }
            $aliases = array_keys($aliases);

            $linkedId = filled($rename['superops_user_id'] ?? null)
                ? (string) $rename['superops_user_id']
                : null;

            $userId = $this->resolveSuperOpsUserIdForRename(
                $byUserId,
                $byEmail,
                $byLocal,
                $to,
                $aliases,
                $linkedId,
            );

            if ($userId === null || $userId === '') {
                $unmatched[] = $label;

                continue;
            }

            if ($this->maybeBindPortalSuperOpsId($rename, $userId)) {
                $bound++;
            }

            $current = $byUserId[$userId] ?? null;
            if ($current === $to) {
                continue;
            }

            if (isset($byEmail[$to]) && $byEmail[$to] !== $userId) {
                Log::warning('SuperOps email align skipped: target email already on another requester', [
                    'client_id' => $client->id,
                    'to' => $to,
                    'from_user_id' => $userId,
                    'holder_user_id' => $byEmail[$to],
                ]);
                $unmatched[] = $label.' (new email already on another SuperOps requester)';

                continue;
            }

            if (isset($claimedTargets[$to])) {
                continue;
            }

            try {
                $this->api->query(<<<'GQL'
                    mutation updateClientUser($input: UpdateClientUserInput!) {
                        updateClientUser(input: $input) {
                            userId
                            email
                        }
                    }
                GQL, [
                    'input' => [
                        'userId' => (string) $userId,
                        'email' => $to,
                    ],
                ]);
                $updated++;
                $claimedTargets[$to] = $userId;

                if ($current !== null) {
                    unset($byEmail[$current]);
                }
                $byEmail[$to] = $userId;
                $byUserId[$userId] = $to;
            } catch (\Throwable $e) {
                Log::warning('SuperOps requester email align failed', [
                    'client_id' => $client->id,
                    'user_id' => $userId,
                    'to' => $to,
                    'error' => $e->getMessage(),
                ]);
                $unmatched[] = $label.' (API error)';
            }
        }

        if ($updated > 0) {
            Cache::forget('superops.requester_count.'.$client->id);
        }

        return [
            'updated' => $updated,
            'bound' => $bound,
            'unmatched' => $unmatched,
        ];
    }

    /**
     * @param  array<string, string>  $byUserId
     * @param  array<string, string>  $byEmail
     * @param  array<string, list<string>>  $byLocal
     * @param  list<string>  $aliases
     */
    private function resolveSuperOpsUserIdForRename(
        array $byUserId,
        array $byEmail,
        array $byLocal,
        string $to,
        array $aliases,
        ?string $linkedId,
    ): ?string {
        if ($linkedId !== null && isset($byUserId[$linkedId])) {
            return $linkedId;
        }

        if (isset($byEmail[$to])) {
            return $byEmail[$to];
        }

        foreach ($aliases as $alias) {
            if (isset($byEmail[$alias])) {
                return $byEmail[$alias];
            }
        }

        $localCandidates = [];
        foreach (array_merge([$to], $aliases) as $email) {
            $local = (string) str($email)->before('@');
            if ($local === '') {
                continue;
            }
            foreach ($byLocal[$local] ?? [] as $uid) {
                $localCandidates[$uid] = true;
            }
        }

        if (count($localCandidates) === 1) {
            return array_key_first($localCandidates);
        }

        return null;
    }

    /**
     * @param  array{portal_user_id?: ?int}  $rename
     */
    private function maybeBindPortalSuperOpsId(array $rename, string $superOpsUserId): bool
    {
        $portalUserId = isset($rename['portal_user_id']) ? (int) $rename['portal_user_id'] : 0;
        if ($portalUserId <= 0) {
            return false;
        }

        $affected = User::query()
            ->whereKey($portalUserId)
            ->where(function ($q) use ($superOpsUserId) {
                $q->whereNull('superops_user_id')
                    ->orWhere('superops_user_id', '!=', $superOpsUserId);
            })
            ->update([
                'superops_user_id' => $superOpsUserId,
                'superops_synced_at' => now(),
            ]);

        return $affected > 0;
    }

    /**
     * @return list<array{userId: string, email: string}>
     */
    private function listClientRequesterEmails(string $accountId): array
    {
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
                        'clientId' => $accountId,
                        'listInfo' => [
                            'page' => $page,
                            'pageSize' => $pageSize,
                        ],
                    ],
                ]);
            } catch (\Throwable $e) {
                Log::warning('SuperOps requester list for email align failed', [
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
                if ($email !== '' && $userId !== '') {
                    $found[] = ['userId' => $userId, 'email' => $email];
                }
            }

            $hasMore = (bool) ($payload['listInfo']['hasMore'] ?? (count($list) === $pageSize));
            $page++;
        } while ($hasMore && $page <= $maxPages);

        return $found;
    }

    /**
     * @param  list<string>  $emails lower-cased
     * @return array<string, string> email => userId
     */
    private function mapRequesterEmailsToUserIds(string $accountId, array $emails): array
    {
        $needed = array_fill_keys($emails, true);
        $found = [];
        foreach ($this->listClientRequesterEmails($accountId) as $row) {
            $email = $row['email'];
            if (isset($needed[$email])) {
                $found[$email] = $row['userId'];
                unset($needed[$email]);
            }
            if ($needed === []) {
                break;
            }
        }

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
                    'listInfo' => [
                        'page' => 1,
                        'pageSize' => 1,
                        'condition' => [
                            'attribute' => 'email',
                            'operator' => 'is',
                            'value' => $user->email,
                        ],
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
