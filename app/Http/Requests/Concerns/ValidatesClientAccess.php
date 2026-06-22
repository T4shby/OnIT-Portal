<?php

namespace App\Http\Requests\Concerns;

use App\Enums\UserRole;
use App\Models\User;

trait ValidatesClientAccess
{
    protected function validateClientAccessForActor(int $clientId, ?User $existingUser = null): void
    {
        $actor = $this->user();

        if (! $actor->canAccessClient($clientId)) {
            $this->validator->errors()->add('client_id', 'You do not have access to this client.');

            return;
        }

        if ($existingUser && $actor->role === UserRole::AccountManager) {
            if ($existingUser->client_id && ! $actor->canAccessClient($existingUser->client_id)) {
                $this->validator->errors()->add('client_id', 'You do not have access to this user.');

                return;
            }

            if ($existingUser->client_id !== $clientId) {
                $this->validator->errors()->add('client_id', 'Only Super Admins may move users between clients.');
            }
        }
    }
}
