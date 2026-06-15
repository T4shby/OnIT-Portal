<?php

namespace App\Models;

use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    use HasFactory;

    protected $fillable = [
        'client_id',
        'entra_object_id',
        'superops_user_id',
        'microsoft_tokens',
        'email',
        'name',
        'role',
        'is_active',
        'last_login_at',
        'superops_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'role' => UserRole::class,
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
            'superops_synced_at' => 'datetime',
            'microsoft_tokens' => 'encrypted:array',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function assignedClients(): BelongsToMany
    {
        return $this->belongsToMany(Client::class, 'client_user')->withTimestamps();
    }

    public function canAccessClient(?int $clientId): bool
    {
        if ($clientId === null) {
            return $this->role === UserRole::SuperAdmin;
        }

        return match ($this->role) {
            UserRole::SuperAdmin => true,
            UserRole::AccountManager => $this->assignedClients()->where('clients.id', $clientId)->exists(),
            UserRole::ClientAdmin, UserRole::ClientUser => $this->client_id === $clientId,
        };
    }

    public function accessibleClientIds(): array
    {
        return match ($this->role) {
            UserRole::SuperAdmin => Client::pluck('id')->toArray(),
            UserRole::AccountManager => $this->assignedClients()->pluck('clients.id')->toArray(),
            UserRole::ClientAdmin, UserRole::ClientUser => $this->client_id ? [$this->client_id] : [],
        };
    }

    public function meetsRoleRequirement(?string $requiredRole): bool
    {
        if ($requiredRole === null) {
            return true;
        }

        $hierarchy = [
            UserRole::ClientUser->value => 1,
            UserRole::ClientAdmin->value => 2,
            UserRole::AccountManager->value => 3,
            UserRole::SuperAdmin->value => 4,
        ];

        $userLevel = $hierarchy[$this->role->value] ?? 0;
        $requiredLevel = $hierarchy[$requiredRole] ?? 0;

        return $userLevel >= $requiredLevel;
    }
}
