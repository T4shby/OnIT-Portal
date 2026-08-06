<?php

namespace App\Services\Portal;

use App\Models\Client;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Single policy for sold (entitled) vs mapped vs live portal products per client.
 *
 * Product keys: superops, m365, huntress, dropsuite, pax8.
 * Feed keys m365_directory / m365_insights map to m365.
 */
class ClientProductService
{
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
     * @return array<string, array{label: string, short: string, mapping_hint: string}>
     */
    public function catalog(): array
    {
        return [
            self::KEY_SUPEROPS => [
                'label' => 'Devices & tickets (SuperOps)',
                'short' => 'S',
                'mapping_hint' => 'Paste SuperOps Account ID from the MSP console for this client.',
            ],
            self::KEY_M365 => [
                'label' => 'Microsoft 365',
                'short' => 'M',
                'mapping_hint' => 'Paste Entra tenant ID when Graph consent is ready.',
            ],
            self::KEY_HUNTRESS => [
                'label' => 'Security (Huntress)',
                'short' => 'H',
                'mapping_hint' => 'Paste Huntress organization ID (numeric org id from Huntress URL).',
            ],
            self::KEY_DROPSUITE => [
                'label' => 'Backups (Dropsuite)',
                'short' => 'D',
                'mapping_hint' => 'Paste Dropsuite organization ID from the sub-reseller portal.',
            ],
            self::KEY_PAX8 => [
                'label' => 'Pax8',
                'short' => 'P',
                'mapping_hint' => 'Paste Pax8 company ID and enable Pax8 access for this client.',
            ],
        ];
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

        return $this->isEntitled($client, $key)
            && $this->isMapped($client, $key)
            && $this->isPlatformReady($key);
    }

    /**
     * Client Admin: show product surface when entitled.
     * Requester/billing: only when live.
     * Technician: always (ops diagnostics).
     */
    public function shouldShowForViewer(Client $client, string $key, ?User $viewer): bool
    {
        $key = $this->normalizeKey($key);

        if ($viewer === null || $viewer->isTeamMember()) {
            return true;
        }

        if ($viewer->isClientAdmin()) {
            return $this->isEntitled($client, $key);
        }

        // Requester / billing: product must be live (sold + mapped + platform).
        return $this->isLive($client, $key);
    }

    /**
     * Client Admin contact-AM copy only when entitled but not fully set up.
     */
    public function needsAccountManagerHelp(Client $client, string $key): bool
    {
        $status = $this->status($client, $key);

        return in_array($status, [self::STATUS_SETUP_NEEDED, self::STATUS_PLATFORM_DOWN, self::STATUS_ERROR], true);
    }

    public function statusLabel(string $status): string
    {
        return match ($status) {
            self::STATUS_NOT_SOLD => 'Not sold',
            self::STATUS_SETUP_NEEDED => 'Setup needed',
            self::STATUS_LIVE => 'Live',
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
     * @return list<array{key: string, short: string, label: string, status: string, colour: string, status_label: string}>
     */
    public function matrixForClient(Client $client): array
    {
        $catalog = $this->catalog();
        $rows = [];

        foreach (self::KEYS as $key) {
            $status = $this->status($client, $key);
            $rows[] = [
                'key' => $key,
                'short' => $catalog[$key]['short'],
                'label' => $catalog[$key]['label'],
                'status' => $status,
                'colour' => $this->statusColour($status),
                'status_label' => $this->statusLabel($status),
            ];
        }

        return $rows;
    }

    /**
     * Merge sold toggles from request into the client's entitlements JSON.
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
     * Does not override an explicit “not sold” (entitled=false).
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
