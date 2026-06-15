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
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'superops_sso_enabled' => 'boolean',
        ];
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
