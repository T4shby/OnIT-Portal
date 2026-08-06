<?php

namespace App\Services\SuperOps;

use App\Models\User;
use Illuminate\Support\Facades\Log;

class SuperOpsUserSyncService
{
    public function __construct(private SuperOpsApiClient $api) {}

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
