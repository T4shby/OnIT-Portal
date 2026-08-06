<?php

namespace App\Services\Portal;

use App\Models\Client;
use App\Models\User;

/**
 * Shared org-vs-personal visibility for SuperOps, M365, Huntress, Dropsuite, etc.
 *
 * - Client Admin (+ staff with client access): organisation-wide people + systems
 * - Requester / Billing: own items only within their organisation
 * - Never cross-tenant
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

    /**
     * Whether a free-text blob (ticket, incident, user row) belongs to this person.
     *
     * @param  list<string|null>  $parts
     */
    public function matchesPerson(User $user, array $parts): bool
    {
        $email = strtolower(trim((string) $user->email));
        if ($email === '') {
            return false;
        }

        $haystack = strtolower(implode(' ', array_filter(array_map(
            static fn ($p) => is_string($p) ? $p : (is_scalar($p) ? (string) $p : ''),
            $parts,
        ))));

        if ($haystack === '') {
            return false;
        }

        if (str_contains($haystack, $email)) {
            return true;
        }

        $local = strstr($email, '@', true);
        if (is_string($local) && strlen($local) >= 4 && str_contains($haystack, strtolower($local))) {
            return true;
        }

        $name = strtolower(trim((string) $user->name));
        if (strlen($name) >= 4 && str_contains($haystack, $name)) {
            return true;
        }

        return false;
    }

    public function matchesEmail(?string $email, User $user): bool
    {
        if (! filled($email)) {
            return false;
        }

        return strtolower(trim($email)) === strtolower(trim((string) $user->email));
    }
}
