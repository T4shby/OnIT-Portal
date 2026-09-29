<?php

namespace App\Services;

use App\Enums\PortalLinkType;
use App\Enums\UserRole;
use App\Models\Client;
use App\Models\PortalLink;
use App\Models\User;
use App\Services\Pax8\Pax8SsoService;
use App\Services\SuperOps\SuperOpsSsoService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class ExternalServicesService
{
    public function getLinksForUser(User $user): Collection
    {
        $clientId = $user->client_id;

        if (! $clientId && ! $user->role->isAdmin()) {
            return collect();
        }

        $cacheKey = 'portal_links.'.($clientId ?? 'admin').'.'.$user->role->value;

        return Cache::remember($cacheKey, 300, function () use ($user, $clientId) {
            return PortalLink::query()
                ->where('is_active', true)
                ->where(function ($query) use ($clientId) {
                    $query->whereNull('client_id');
                    if ($clientId) {
                        $query->orWhere('client_id', $clientId);
                    }
                })
                ->orderBy('display_order')
                ->get()
                ->filter(fn (PortalLink $link) => $user->meetsRoleRequirement($link->required_role))
                ->filter(function (PortalLink $link) use ($user) {
                    if ($link->link_type === PortalLinkType::SuperOpsSso) {
                        return app(SuperOpsSsoService::class)->isEnabledForUser($user);
                    }

                    if ($link->link_type === PortalLinkType::Pax8Sso) {
                        return app(Pax8SsoService::class)->isEnabledForUser($user);
                    }

                    return true;
                })
                ->unique(fn (PortalLink $link) => $link->link_type->value)
                ->values();
        });
    }

    public function getDefaultLinks(): array
    {
        return [
            [
                'name' => 'SuperOps',
                'description' => 'Support portal.',
                'url' => route('integrations.superops.launch'),
                'link_type' => PortalLinkType::SuperOpsSso,
                'icon' => 'superops',
                'display_order' => 1,
                'open_in_new_tab' => false,
            ],
            [
                'name' => 'Pax8',
                'description' => 'Licensing portal.',
                'url' => route('integrations.pax8.launch'),
                'link_type' => PortalLinkType::Pax8Sso,
                'icon' => 'pax8',
                'display_order' => 2,
                'open_in_new_tab' => false,
            ],
        ];
    }

    public function syncDefaultLinks(): void
    {
        $names = [];

        foreach ($this->getDefaultLinks() as $link) {
            if (empty($link['url'])) {
                continue;
            }

            $names[] = $link['name'];

            PortalLink::updateOrCreate(
                ['client_id' => null, 'name' => $link['name']],
                [
                    'description' => $link['description'],
                    'url' => $link['url'],
                    'link_type' => $link['link_type'] ?? PortalLinkType::External,
                    'icon' => $link['icon'],
                    'display_order' => $link['display_order'],
                    'is_active' => true,
                    'open_in_new_tab' => $link['open_in_new_tab'] ?? true,
                ],
            );
        }

        if ($names !== []) {
            PortalLink::query()
                ->whereNull('client_id')
                ->whereNotIn('name', $names)
                ->delete();
        }

        $this->clearCache();
    }

    /**
     * Forget cached link lists after a link changes. A global link (null client) is
     * in every client's cached list, so it clears them all; before, it cleared only
     * the staff lists and a deleted / deactivated global link stayed visible to
     * every customer for up to 5 minutes.
     */
    public function clearCache(?int $clientId = null): void
    {
        $roles = array_map(static fn (UserRole $role): string => $role->value, UserRole::cases());
        $clientIds = $clientId !== null ? [$clientId] : Client::query()->pluck('id')->all();

        foreach ($clientIds as $id) {
            foreach ($roles as $role) {
                Cache::forget("portal_links.{$id}.{$role}");
            }
        }
        foreach ($roles as $role) {
            Cache::forget("portal_links.admin.{$role}");
        }
    }
}
