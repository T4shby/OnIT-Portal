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
        'entra_tenant_id',
        'entra_group_id',
        'entra_sync_enabled',
        'entra_synced_at',
        'onboarding_checklist',
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
        ];
    }

    public function hasEntraSyncConfigured(): bool
    {
        return $this->entra_sync_enabled
            && filled($this->entra_tenant_id);
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
}
