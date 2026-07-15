<?php

namespace App\Models;

use App\Enums\EntraIdentityType;
use App\Enums\UserProvisionSource;
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
        'entra_identity_type',
        'superops_user_id',
        'microsoft_tokens',
        'email',
        'name',
        'role',
        'is_active',
        'portal_login_enabled',
        'provisioned_by',
        'last_login_at',
        'entra_synced_at',
        'superops_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'role' => UserRole::class,
            'entra_identity_type' => EntraIdentityType::class,
            'provisioned_by' => UserProvisionSource::class,
            'is_active' => 'boolean',
            'portal_login_enabled' => 'boolean',
            'last_login_at' => 'datetime',
            'entra_synced_at' => 'datetime',
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
            UserRole::ClientAdmin,
            UserRole::ClientBillingAdmin,
            UserRole::ClientRequester,
            UserRole::ClientUser => $this->client_id === $clientId,
        };
    }

    public function accessibleClientIds(): array
    {
        return match ($this->role) {
            UserRole::SuperAdmin => Client::pluck('id')->toArray(),
            UserRole::AccountManager => $this->assignedClients()->pluck('clients.id')->toArray(),
            UserRole::ClientAdmin,
            UserRole::ClientBillingAdmin,
            UserRole::ClientRequester,
            UserRole::ClientUser => $this->client_id ? [$this->client_id] : [],
        };
    }

    public function isTeamMember(): bool
    {
        return $this->role->isAdmin();
    }

    public function isClientRequester(): bool
    {
        return $this->role->isClientRequester();
    }

    public function isClientBillingAdmin(): bool
    {
        return $this->role->isClientBillingAdmin();
    }

    public function isClientAdmin(): bool
    {
        return $this->role->isClientAdmin();
    }

    public function canUseClientSupport(): bool
    {
        return $this->role->isClientFacing();
    }

    public function canAccessClientBilling(): bool
    {
        return $this->role->canAccessClientBilling();
    }

    public function canViewClientAdminDashboard(): bool
    {
        return $this->role->canViewClientAdminDashboard();
    }

    public function canViewMicrosoft365Directory(): bool
    {
        return $this->role->canViewMicrosoft365Directory();
    }

    public function canViewOrganisationTickets(): bool
    {
        return $this->role->canViewOrganisationTickets();
    }

    public function canViewClientAssets(): bool
    {
        return $this->role->canViewClientAssets();
    }

    public function meetsRoleRequirement(?string $requiredRole): bool
    {
        if ($requiredRole === null) {
            return true;
        }

        if ($requiredRole === UserRole::ClientUser->value) {
            $requiredRole = UserRole::ClientRequester->value;
        }

        $hierarchy = [
            UserRole::ClientRequester->value => 1,
            UserRole::ClientUser->value => 1,
            UserRole::ClientBillingAdmin->value => 2,
            UserRole::ClientAdmin->value => 3,
            UserRole::AccountManager->value => 4,
            UserRole::SuperAdmin->value => 5,
        ];

        $userLevel = $hierarchy[$this->role->value] ?? 0;
        $requiredLevel = $hierarchy[$requiredRole] ?? 0;

        return $userLevel >= $requiredLevel;
    }
}
