<?php

namespace App\Services\Portal;

use App\Models\Client;
use App\Models\User;

/**
 * Shared org-vs-personal visibility for SuperOps, M365, Huntress, Dropsuite, etc.
 *
 * Three distinct audiences (do not conflate):
 * - Technician Admin (`super_admin` / `account_manager`): all assigned customers
 *   (super_admin = every client); full org data per customer + Staff Admin tools
 * - Client Admin (`client_admin`): only their own customer organisation; full
 *   people + systems for that one tenant - never other customers
 * - Requester / Billing: personal items only within their organisation
 */
class ClientVisibilityService
{
    /**
     * May open any client systems surface for this client (nav, area access).
     */
    public function canAccessClientSystems(User $user, Client $client): bool
    {
        if ($user->isTeamMember()) {
            return $user->canAccessClient($client->id);
        }

        return $user->role->isClientFacing()
            && filled($user->client_id)
            && (int) $user->client_id === (int) $client->id;
    }

    /**
     * Sees every person + all system cases for the organisation (not just their own).
     */
    public function canViewOrganisationWide(User $user, Client $client): bool
    {
        if ($user->isTeamMember()) {
            return $user->canAccessClient($client->id);
        }

        return $user->isClientAdmin()
            && filled($user->client_id)
            && (int) $user->client_id === (int) $client->id;
    }

    public function matchesEmail(?string $email, User $user): bool
    {
        if (! filled($email)) {
            return false;
        }

        return strtolower(trim($email)) === strtolower(trim((string) $user->email));
    }
}
