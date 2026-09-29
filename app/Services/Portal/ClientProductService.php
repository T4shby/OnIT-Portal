<?php

namespace App\Services\Portal;

use App\Models\Client;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Single policy for portal product entitlements and modular licence vendors.
 *
 * Catalog kinds:
 * - service: products On IT sells to the client (SuperOps, M365, Huntress, Dropsuite…)
 * - licence_vendor: who they buy Microsoft/cloud licences through (Pax8 now; add more later)
 *
 * Feed keys m365_directory / m365_insights map to m365.
 *
 * To add another licence vendor later: append to KEYS + catalog() + isMapped/isPlatformReady
 * + a blade partial under resources/views/admin/clients/products/.
 */
class ClientProductService
{
    public const KIND_SERVICE = 'service';

    public const KIND_LICENCE_VENDOR = 'licence_vendor';

    public const KEY_SUPEROPS = 'superops';

    public const KEY_M365 = 'm365';

    public const KEY_HUNTRESS = 'huntress';

    public const KEY_DROPSUITE = 'dropsuite';

    public const KEY_PAX8 = 'pax8';

    /** @var list<string> */
    public const KEYS = [
        self::KEY_SUPEROPS,
        self::KEY_M365,
        self::KEY_HUNTRESS,
        self::KEY_DROPSUITE,
        self::KEY_PAX8,
    ];

    public const STATUS_NOT_SOLD = 'not_sold';

    public const STATUS_SETUP_NEEDED = 'setup_needed';

    public const STATUS_LIVE = 'live';

    public const STATUS_PLATFORM_DOWN = 'platform_down';

    public const STATUS_ERROR = 'error';

    /**
     * Full modular catalog (services + licence vendors).
     *
     * @return array<string, array{
     *   kind: string,
     *   label: string,
     *   short: string,
     *   mapping_hint: string,
     *   toggle_label: string,
     *   form_partial: string
     * }>
     */
    public function catalog(): array
    {
        return [
            self::KEY_SUPEROPS => [
                'kind' => self::KIND_SERVICE,
                'label' => 'Devices & tickets (SuperOps)',
                'short' => 'S',
                'mapping_hint' => 'Paste SuperOps Account ID from the MSP console for this client.',
                'toggle_label' => 'Sold to this client',
                'form_partial' => 'admin.clients.products._superops',
            ],
            self::KEY_M365 => [
                'kind' => self::KIND_SERVICE,
                'label' => 'Microsoft 365',
                'short' => 'M',
                'mapping_hint' => 'Portal directory and licence insight when Entra tenant is linked (fields below / Connect).',
                'toggle_label' => 'Sold to this client',
                'form_partial' => 'admin.clients.products._m365',
            ],
            self::KEY_HUNTRESS => [
                'kind' => self::KIND_SERVICE,
                'label' => 'Security (Huntress)',
                'short' => 'H',
                'mapping_hint' => 'Paste Huntress organization ID (numeric org id from Huntress URL).',
                'toggle_label' => 'Sold to this client',
                'form_partial' => 'admin.clients.products._huntress',
            ],
            self::KEY_DROPSUITE => [
                'kind' => self::KIND_SERVICE,
                'label' => 'Backups (Dropsuite)',
                'short' => 'D',
                'mapping_hint' => 'Paste Dropsuite organization ID from the sub-reseller portal.',
                'toggle_label' => 'Sold to this client',
                'form_partial' => 'admin.clients.products._dropsuite',
            ],
            // Licence vendors - not MSP “products”; where the client buys cloud licences.
            self::KEY_PAX8 => [
                'kind' => self::KIND_LICENCE_VENDOR,
                'label' => 'Pax8',
                'short' => 'P',
                'mapping_hint' => 'Assign when this client’s Microsoft / cloud licences are purchased via Pax8. Paste company ID and enable portal access.',
                'toggle_label' => 'Licences via this vendor',
                'form_partial' => 'admin.clients.products._pax8',
            ],
            // Future: e.g. KEY_LEGACY_VENDOR => [ 'kind' => KIND_LICENCE_VENDOR, … ]
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function serviceCatalog(): array
    {
        return array_filter(
            $this->catalog(),
            static fn (array $meta): bool => ($meta['kind'] ?? self::KIND_SERVICE) === self::KIND_SERVICE,
        );
    }

    /**
     * Modular licence / marketplace vendors (Pax8 today; more later).
     *
     * @return array<string, array<string, mixed>>
     */
    public function licenceVendorCatalog(): array
    {
        return array_filter(
            $this->catalog(),
            static fn (array $meta): bool => ($meta['kind'] ?? '') === self::KIND_LICENCE_VENDOR,
        );
    }

    public function kind(string $key): string
    {
        $key = $this->normalizeKey($key);
        $catalog = $this->catalog();

        return (string) ($catalog[$key]['kind'] ?? self::KIND_SERVICE);
    }

    public function isLicenceVendor(string $key): bool
    {
        return $this->kind($key) === self::KIND_LICENCE_VENDOR;
    }

    public function normalizeKey(string $key): string
    {
        return match ($key) {
            'm365_directory', 'm365_insights' => self::KEY_M365,
            default => $key,
        };
    }

    public function isKnownKey(string $key): bool
    {
        return in_array($this->normalizeKey($key), self::KEYS, true);
    }

    public function isEntitled(Client $client, string $key): bool
    {
        $key = $this->normalizeKey($key);
        $entry = $this->entitlementEntry($client, $key);

        if (is_array($entry) && array_key_exists('entitled', $entry)) {
            return (bool) $entry['entitled'];
        }

        // Legacy rows before JSON was set: treat mapped as entitled.
        return $this->isMapped($client, $key);
    }

    public function isMapped(Client $client, string $key): bool
    {
        $key = $this->normalizeKey($key);

        return match ($key) {
            self::KEY_SUPEROPS => filled($client->superops_account_id),
            self::KEY_M365 => filled($client->entra_tenant_id),
            self::KEY_HUNTRESS => filled($client->huntress_organization_id),
            self::KEY_DROPSUITE => filled($client->dropsuite_organization_id),
            self::KEY_PAX8 => filled($client->pax8_company_id) && (bool) $client->pax8_sso_enabled,
            default => false,
        };
    }

    public function isPlatformReady(string $key): bool
    {
        $key = $this->normalizeKey($key);

        return match ($key) {
            self::KEY_SUPEROPS => filled(config('services.superops.api_token'))
                && filled(config('services.superops.subdomain')),
            self::KEY_M365 => filled(config('services.entra_sync.client_id'))
                && filled(config('services.entra_sync.client_secret')),
            self::KEY_HUNTRESS => (bool) config('services.huntress.enabled')
                && filled(config('services.huntress.api_key'))
                && filled(config('services.huntress.api_secret')),
            self::KEY_DROPSUITE => (bool) config('services.dropsuite.enabled')
                && filled(config('services.dropsuite.reseller_token'))
                && filled(config('services.dropsuite.auth_token')),
            self::KEY_PAX8 => (bool) config('services.pax8.enabled', true),
            default => false,
        };
    }

    /**
     * @return self::STATUS_*
     */
    public function status(Client $client, string $key): string
    {
        $key = $this->normalizeKey($key);

        if (! $this->isEntitled($client, $key)) {
            return self::STATUS_NOT_SOLD;
        }

        if (! $this->isMapped($client, $key)) {
            return self::STATUS_SETUP_NEEDED;
        }

        if (! $this->isPlatformReady($key)) {
            return self::STATUS_PLATFORM_DOWN;
        }

        if ($this->hasRecentFailure($client, $key)) {
            return self::STATUS_ERROR;
        }

        return self::STATUS_LIVE;
    }

    public function isLive(Client $client, string $key): bool
    {
        return $this->status($client, $key) === self::STATUS_LIVE;
    }

    /**
     * Whether metrics refresh / prewarm should run for this product.
     */
    public function shouldRefresh(Client $client, string $key): bool
    {
        $key = $this->normalizeKey($key);

        // Licence vendors are assignment only - not dashboard feed prewarm.
        if ($this->isLicenceVendor($key)) {
            return false;
        }

        return $this->isEntitled($client, $key)
            && $this->isMapped($client, $key)
            && $this->isPlatformReady($key);
    }

    /**
     * Client Admin: show product surface when entitled (Support & SLA, gates).
     * Requester/billing: only when live.
     * Technician: always (ops diagnostics).
     * Licence vendors are staff/tracking only (not system-health tiles).
     *
     * For System health overview tiles (incl. not-sold upsell for Client Admins),
     * use {@see shouldShowOverviewTile()}.
     */
    public function shouldShowForViewer(Client $client, string $key, ?User $viewer): bool
    {
        $key = $this->normalizeKey($key);

        if ($this->isLicenceVendor($key)) {
            return false;
        }

        if ($viewer === null || $viewer->isTeamMember()) {
            return true;
        }

        if ($viewer->isClientAdmin()) {
            return $this->isEntitled($client, $key);
        }

        return $this->isLive($client, $key);
    }

    /**
     * System health tile visibility on Dashboard glance (legacy feed tiles).
     *
     * - Technician: all service tiles
     * - Client Admin: all service tiles (not sold → upsell placeholder)
     * - Requester/billing: live products only
     * - Licence vendors: never
     */
    public function shouldShowOverviewTile(Client $client, string $key, ?User $viewer): bool
    {
        $key = $this->normalizeKey($key);

        if ($this->isLicenceVendor($key)) {
            return false;
        }

        if ($viewer === null || $viewer->isTeamMember() || $viewer->isClientAdmin()) {
            return true;
        }

        return $this->isLive($client, $key);
    }

    /**
     * Client Admin contact-AM copy only when entitled but not fully set up.
     */
    public function needsAccountManagerHelp(Client $client, string $key): bool
    {
        if ($this->isLicenceVendor($key)) {
            return false;
        }

        $status = $this->status($client, $key);

        return in_array($status, [self::STATUS_SETUP_NEEDED, self::STATUS_PLATFORM_DOWN, self::STATUS_ERROR], true);
    }

    public function statusLabel(string $status, ?string $key = null): string
    {
        $vendor = $key !== null && $this->isLicenceVendor($key);

        return match ($status) {
            self::STATUS_NOT_SOLD => $vendor ? 'Not assigned' : 'Not sold',
            self::STATUS_SETUP_NEEDED => $vendor ? 'Link needed' : 'Setup needed',
            self::STATUS_LIVE => $vendor ? 'Assigned' : 'Live',
            self::STATUS_PLATFORM_DOWN => 'Platform down',
            self::STATUS_ERROR => 'Error',
            default => $status,
        };
    }

    public function statusColour(string $status): string
    {
        return match ($status) {
            self::STATUS_NOT_SOLD => 'grey',
            self::STATUS_SETUP_NEEDED => 'amber',
            self::STATUS_LIVE => 'green',
            self::STATUS_PLATFORM_DOWN, self::STATUS_ERROR => 'red',
            default => 'grey',
        };
    }

    /**
     * Matrix for Admin → Clients list chips.
     *
     * @return list<array{key: string, short: string, label: string, status: string, colour: string, status_label: string, kind: string}>
     */
    public function matrixForClient(Client $client): array
    {
        $catalog = $this->catalog();
        $rows = [];

        foreach (self::KEYS as $key) {
            if (! isset($catalog[$key])) {
                continue;
            }
            $status = $this->status($client, $key);
            $rows[] = [
                'key' => $key,
                'short' => $catalog[$key]['short'],
                'label' => $catalog[$key]['label'],
                'kind' => $catalog[$key]['kind'],
                'status' => $status,
                'colour' => $this->statusColour($status),
                'status_label' => $this->statusLabel($status, $key),
            ];
        }

        return $rows;
    }

    /**
     * @return list<array{key: string, short: string, label: string, status: string, colour: string, status_label: string, kind: string}>
     */
    public function serviceMatrixForClient(Client $client): array
    {
        return array_values(array_filter(
            $this->matrixForClient($client),
            static fn (array $row): bool => $row['kind'] === self::KIND_SERVICE,
        ));
    }

    /**
     * @return list<array{key: string, short: string, label: string, status: string, colour: string, status_label: string, kind: string}>
     */
    public function licenceVendorMatrixForClient(Client $client): array
    {
        return array_values(array_filter(
            $this->matrixForClient($client),
            static fn (array $row): bool => $row['kind'] === self::KIND_LICENCE_VENDOR,
        ));
    }

    /**
     * Merge sold / vendor-assigned toggles from request into the client's entitlements JSON.
     *
     * @param  array<string, bool>  $entitledByKey
     */
    public function applyEntitlements(Client $client, array $entitledByKey): void
    {
        $current = is_array($client->product_entitlements) ? $client->product_entitlements : [];
        $now = now()->toIso8601String();

        foreach (self::KEYS as $key) {
            if (! array_key_exists($key, $entitledByKey)) {
                continue;
            }

            $entitled = (bool) $entitledByKey[$key];
            $entry = is_array($current[$key] ?? null) ? $current[$key] : [];

            if ($entitled) {
                $entry['entitled'] = true;
                if (empty($entry['entitled_at'])) {
                    $entry['entitled_at'] = $now;
                }
            } else {
                $entry['entitled'] = false;
            }

            $current[$key] = $entry;
        }

        $client->product_entitlements = $current;
        $client->save();
    }

    /**
     * Ensure mapped products are marked entitled when entitlements never set for that key.
     * Does not override an explicit “not sold / not assigned” (entitled=false).
     */
    public function syncEntitlementsFromMappings(Client $client): void
    {
        $current = is_array($client->product_entitlements) ? $client->product_entitlements : [];
        $now = now()->toIso8601String();
        $changed = false;

        foreach (self::KEYS as $key) {
            if (! $this->isMapped($client, $key)) {
                continue;
            }

            $entry = is_array($current[$key] ?? null) ? $current[$key] : [];
            if (array_key_exists('entitled', $entry)) {
                continue;
            }

            $entry['entitled'] = true;
            $entry['entitled_at'] = $entry['entitled_at'] ?? $now;
            $current[$key] = $entry;
            $changed = true;
        }

        if ($changed) {
            $client->product_entitlements = $current;
            $client->save();
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function entitlementEntry(Client $client, string $key): ?array
    {
        $all = $client->product_entitlements;
        if (! is_array($all)) {
            return null;
        }

        $entry = $all[$key] ?? null;

        return is_array($entry) ? $entry : null;
    }

    private function hasRecentFailure(Client $client, string $key): bool
    {
        $cacheKey = match ($this->normalizeKey($key)) {
            self::KEY_SUPEROPS => 'superops_dashboard.last_result.'.$client->id,
            self::KEY_HUNTRESS => 'huntress_security.last_result.'.$client->id,
            self::KEY_DROPSUITE => 'dropsuite_backup.last_result.'.$client->id,
            self::KEY_M365 => 'm365_insights.last_result.'.$client->id,
            default => null,
        };

        if ($cacheKey === null) {
            return false;
        }

        $result = Cache::get($cacheKey);
        if (! is_array($result) || ($result['success'] ?? true) !== false) {
            return false;
        }

        $at = $result['finished_at'] ?? $result['at'] ?? null;
        if (! filled($at)) {
            return true;
        }

        try {
            return Carbon::parse((string) $at)->gt(now()->subDay());
        } catch (\Throwable) {
            return true;
        }
    }
}
