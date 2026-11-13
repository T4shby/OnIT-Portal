<?php

namespace App\Services\Gdap;

/**
 * Standing GDAP role set for On IT technicians.
 *
 * Global Administrator is intentionally absent: Microsoft will not auto-extend
 * a relationship that includes it. Privileged Role Administrator can Accept
 * the portal Graph app and assign directory roles (including Exchange
 * Administrator on that app for DKIM).
 *
 * Template ids are the built-in Entra role definition ids.
 */
class GdapRoleSet
{
    public const GLOBAL_ADMINISTRATOR = '62e90394-69f5-4237-9190-012177145e10';

    /**
     * @var array<string, string> role definition id => display name
     */
    public const ROLES = [
        'e8611ab8-c189-46e8-94e1-60213ab1f814' => 'Privileged Role Administrator',
        '9b895d92-2cd3-44c7-9d02-a6ac2d5ea5c3' => 'Application Administrator',
        '29232cdf-9323-42fd-ade2-1d097af3e4de' => 'Exchange Administrator',
        'fe930be7-5e62-47db-91af-98c3a49a38b1' => 'User Administrator',
        'fdd7a751-b60b-444a-984c-02652fe8fa1c' => 'Groups Administrator',
        '194ae4cb-b126-40b2-bd5b-6091b380977d' => 'Security Administrator',
        '7be44c8a-adaf-4e2a-84d6-ab2649eac61a' => 'Privileged Authentication Administrator',
        '729827e3-9c14-49f7-bb1b-9608f156bbb8' => 'Helpdesk Administrator',
        'f023fd81-a637-4b56-95fd-791ac0226033' => 'Service Support Administrator',
    ];

    /**
     * @return list<string>
     */
    public static function ids(): array
    {
        return array_keys(self::ROLES);
    }

    public static function name(string $roleId): string
    {
        $id = strtolower($roleId);

        return self::ROLES[$id] ?? $roleId;
    }

    /**
     * @param  list<string>  $have
     * @return list<string> display names of standing roles that are absent
     */
    public static function missingNames(array $have): array
    {
        $have = array_map('strtolower', $have);
        $missing = [];
        foreach (self::ROLES as $id => $name) {
            if (! in_array($id, $have, true)) {
                $missing[] = $name;
            }
        }

        return $missing;
    }

    /**
     * @param  list<string>  $have
     * @param  list<string>  $need
     */
    public static function covers(array $have, array $need): bool
    {
        $have = array_map('strtolower', $have);
        foreach ($need as $id) {
            if (! in_array(strtolower($id), $have, true)) {
                return false;
            }
        }

        return $need !== [];
    }
}
