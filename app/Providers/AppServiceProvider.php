<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\URL;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(\App\Services\Portal\DashboardFeedRegistry::class, function ($app) {
            return new \App\Services\Portal\DashboardFeedRegistry([
                // Order = System health tile order (overview partials only).
                $app->make(\App\Services\Portal\Feeds\SuperOpsDashboardFeed::class),
                $app->make(\App\Services\Portal\Feeds\HuntressDashboardFeed::class),
                $app->make(\App\Services\Portal\Feeds\DropsuiteDashboardFeed::class),
                $app->make(\App\Services\Portal\Feeds\M365InsightsDashboardFeed::class),
                // Prewarm-only (no overview tile — own Microsoft 365 page).
                $app->make(\App\Services\Portal\Feeds\M365DirectoryDashboardFeed::class),
            ]);
        });
    }

    public function boot(): void
    {
        if (app()->isProduction()) {
            URL::forceScheme('https');
            URL::forceRootUrl(config('app.url'));
        }

        RateLimiter::for('auth-callback', function (Request $request) {
            return Limit::perMinute(6)->by($request->ip());
        });

        RateLimiter::for('integrations-launch', function (Request $request) {
            return Limit::perMinute(30)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('entra-sync', function (Request $request) {
            $clientId = $request->route('client')?->id ?? 'unknown';

            return Limit::perMinute(2)->by($request->user()?->id.'|'.$clientId);
        });
    }
}
