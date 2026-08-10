<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Client extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'superops_account_id',
        'superops_sso_enabled',
        'pax8_company_id',
        'pax8_sso_enabled',
        'dropsuite_organization_id',
        'huntress_organization_id',
        'entra_tenant_id',
        'entra_license_tier',
        'entra_group_id',
        'entra_superops_app_id',
        'entra_superops_sso_app_id',
        'entra_sync_enabled',
        'entra_synced_at',
        'onboarding_checklist',
        'product_entitlements',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'superops_sso_enabled' => 'boolean',
            'pax8_sso_enabled' => 'boolean',
            'entra_sync_enabled' => 'boolean',
            'entra_synced_at' => 'datetime',
            'onboarding_checklist' => 'array',
            'product_entitlements' => 'array',
        ];
    }

    public function products(): \App\Services\Portal\ClientProductService
    {
        return app(\App\Services\Portal\ClientProductService::class);
    }

    public function isProductEntitled(string $key): bool
    {
        return $this->products()->isEntitled($this, $key);
    }

    public function isProductMapped(string $key): bool
    {
        return $this->products()->isMapped($this, $key);
    }

    public function productStatus(string $key): string
    {
        return $this->products()->status($this, $key);
    }

    public function hasSuperOpsLinked(): bool
    {
        return $this->products()->isMapped($this, 'superops');
    }

    public function hasHuntressLinked(): bool
    {
        return $this->products()->isMapped($this, 'huntress');
    }

    public function hasDropsuiteLinked(): bool
    {
        return $this->products()->isMapped($this, 'dropsuite');
    }

    /** Microsoft 365 directory / licence insight (Graph tenant on the client). */
    public function hasM365Linked(): bool
    {
        return $this->products()->isMapped($this, 'm365');
    }

    /**
     * Whether a dashboard feed key is entitled + mapped (usable data path).
     */
    public function hasFeedLinked(string $feedKey): bool
    {
        $products = $this->products();

        return $products->isEntitled($this, $feedKey)
            && $products->isMapped($this, $feedKey);
    }

    public function hasEntraSyncConfigured(): bool
    {
        return $this->entra_sync_enabled
            && filled($this->entra_tenant_id);
    }

    public function hasEntraSyncPrerequisites(): bool
    {
        if (! $this->hasEntraSyncConfigured()) {
            return false;
        }

        $tier = $this->entra_license_tier ?? 'free';

        if ($tier === 'p1') {
            return filled($this->entra_group_id);
        }

        return filled($this->entra_group_id) && filled($this->entra_superops_app_id);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function assignedUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'client_user')->withTimestamps();
    }

    public function portalLinks(): HasMany
    {
        return $this->hasMany(PortalLink::class);
    }

    public function notices(): HasMany
    {
        return $this->hasMany(ClientNotice::class);
    }

    public function recommendations(): HasMany
    {
        return $this->hasMany(ClientRecommendation::class);
    }

    public function opportunities(): HasMany
    {
        return $this->hasMany(ClientOpportunity::class);
    }

    public function metricDailySnapshots(): HasMany
    {
        return $this->hasMany(ClientMetricDailySnapshot::class);
    }
}
