<?php

namespace App\Providers;

use App\Enums\UserRole;
use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\ClientNotice;
use App\Models\ClientOpportunity;
use App\Models\ClientRecommendation;
use App\Models\PortalLink;
use App\Models\Setting;
use App\Models\User;
use App\Policies\ActivityLogPolicy;
use App\Policies\ClientNoticePolicy;
use App\Policies\ClientOpportunityPolicy;
use App\Policies\ClientPolicy;
use App\Policies\ClientRecommendationPolicy;
use App\Policies\PortalLinkPolicy;
use App\Policies\SettingPolicy;
use App\Policies\UserPolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;

class AuthServiceProvider extends ServiceProvider
{
    protected $policies = [
        Client::class => ClientPolicy::class,
        User::class => UserPolicy::class,
        PortalLink::class => PortalLinkPolicy::class,
        ClientNotice::class => ClientNoticePolicy::class,
        ClientRecommendation::class => ClientRecommendationPolicy::class,
        ClientOpportunity::class => ClientOpportunityPolicy::class,
        Setting::class => SettingPolicy::class,
        ActivityLog::class => ActivityLogPolicy::class,
    ];

    public function boot(): void
    {
        $this->registerPolicies();

        Gate::define('access-admin', function (User $user) {
            return $user->role->isAdmin();
        });

        Gate::define('manage-all-clients', function (User $user) {
            return $user->role === UserRole::SuperAdmin;
        });

        Gate::define('view-m365-directory', function (User $user) {
            if ($user->role !== UserRole::ClientAdmin) {
                return false;
            }

            return filled($user->client?->entra_tenant_id)
                && filled(config('services.entra_sync.client_id'))
                && filled(config('services.entra_sync.client_secret'));
        });
    }
}
