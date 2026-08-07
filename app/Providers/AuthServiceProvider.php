<?php

namespace App\Providers;

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
            return $user->role === \App\Enums\UserRole::SuperAdmin;
        });

        Gate::define('view-client-admin-dashboard', function (User $user) {
            return $user->canViewClientAdminDashboard() && filled($user->client_id);
        });

        // Client Admin only — Organisation overview + nav label.
        Gate::define('view-organisation-wide', function (User $user) {
            if (! filled($user->client_id) || ! $user->canViewOrganisationWide()) {
                return false;
            }

            return true;
        });

        // Requester / billing — personal My Systems (same /client-admin URL, filtered data).
        Gate::define('view-my-systems', function (User $user) {
            return $user->canViewClientAdminDashboard()
                && filled($user->client_id)
                && ! $user->canViewOrganisationWide();
        });

        Gate::define('view-m365-directory', function (User $user) {
            if (! $user->canViewMicrosoft365Directory() || ! filled($user->client_id)) {
                return false;
            }

            $client = $user->client;
            if ($client === null) {
                return false;
            }

            $products = app(\App\Services\Portal\ClientProductService::class);

            return $products->shouldRefresh($client, 'm365');
        });

        Gate::define('view-huntress-security', function (User $user) {
            if (! filled($user->client_id) || ! $user->role->isClientFacing()) {
                return false;
            }

            $client = $user->client;
            if ($client === null) {
                return false;
            }

            return app(\App\Services\Portal\ClientProductService::class)->shouldRefresh($client, 'huntress');
        });

        Gate::define('access-client-billing', function (User $user) {
            return $user->canAccessClientBilling();
        });
    }
}
