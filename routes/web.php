<?php

use App\Http\Controllers\Admin\ActivityLogController;
use App\Http\Controllers\Admin\ClientController;
use App\Http\Controllers\Admin\ClientMicrosoft365DirectoryController;
use App\Http\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Admin\IntegrationHealthController;
use App\Http\Controllers\Admin\NoticeController;
use App\Http\Controllers\Admin\OpportunityController;
use App\Http\Controllers\Admin\PortalLinkController;
use App\Http\Controllers\Admin\RecommendationController;
use App\Http\Controllers\Admin\SettingController;
use App\Http\Controllers\Admin\TeamController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Auth\MicrosoftAuthController;
use App\Http\Controllers\ClientAdminDashboardController;
use App\Http\Controllers\ClientBackupsController;
use App\Http\Controllers\ClientReportsController;
use App\Http\Controllers\HuntressSecurityController;
use App\Http\Controllers\Admin\ClientDropsuiteBackupController;
use App\Http\Controllers\Admin\ClientHuntressSecurityController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Microsoft365DirectoryController;
use App\Http\Controllers\Integrations\Pax8LaunchController;
use App\Http\Controllers\Integrations\SuperOpsLaunchController;
use App\Http\Controllers\ContactSupportController;
use App\Http\Controllers\SupportController;
use App\Enums\UserRole;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route('login'));

Route::middleware('guest')->group(function () {
    Route::get('/login', [MicrosoftAuthController::class, 'showLogin'])->name('login');
    Route::get('/auth/microsoft', [MicrosoftAuthController::class, 'redirect'])->name('auth.microsoft');
    Route::get('/auth/microsoft/callback', [MicrosoftAuthController::class, 'callback'])
        ->middleware('throttle:auth-callback')
        ->name('auth.microsoft.callback');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [MicrosoftAuthController::class, 'logout'])->name('logout');
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    Route::get('/reports', [ClientReportsController::class, 'index'])
        ->middleware('can:view-organisation-wide')
        ->name('reports.index');

    Route::prefix('contact-support')->name('contact-support.')->middleware('can:contact-support')->group(function () {
        Route::get('/', [ContactSupportController::class, 'index'])->name('index');
        Route::get('/new-starter', [ContactSupportController::class, 'createNewStarter'])->name('new-starter');
        Route::post('/new-starter', [ContactSupportController::class, 'storeNewStarter'])
            ->middleware('throttle:12,1')
            ->name('new-starter.store');
    });

    Route::prefix('support')->name('support.')->group(function () {
        Route::get('/', [SupportController::class, 'index'])->name('index');
        Route::get('/create', [SupportController::class, 'create'])->name('create');
        Route::post('/', [SupportController::class, 'store'])->name('store');
        Route::get('/{ticketId}', [SupportController::class, 'show'])->name('show');
    });

    Route::get('/integrations/superops/launch', SuperOpsLaunchController::class)
        ->middleware('throttle:integrations-launch')
        ->name('integrations.superops.launch');

    Route::get('/integrations/pax8/launch', Pax8LaunchController::class)
        ->middleware('throttle:integrations-launch')
        ->name('integrations.pax8.launch');

    Route::get('/microsoft-365/directory', [Microsoft365DirectoryController::class, 'index'])
        ->middleware('can:view-m365-directory')
        ->name('microsoft-365.directory');

    Route::get('/microsoft-365/directory/export', [Microsoft365DirectoryController::class, 'export'])
        ->middleware('can:view-m365-directory')
        ->name('microsoft-365.directory.export');

    Route::get('/microsoft-365/directory/live', [Microsoft365DirectoryController::class, 'live'])
        ->middleware('can:view-m365-directory')
        ->name('microsoft-365.directory.live');

    Route::post('/microsoft-365/directory/refresh', [Microsoft365DirectoryController::class, 'refresh'])
        ->middleware(['can:view-m365-directory', 'throttle:6,1'])
        ->name('microsoft-365.directory.refresh');

    Route::get('/services/support-devices', [ClientAdminDashboardController::class, 'index'])
        ->middleware('can:view-client-admin-dashboard')
        ->name('client-admin.dashboard');

    Route::get('/services/support-devices/live', [ClientAdminDashboardController::class, 'live'])
        ->middleware('can:view-client-admin-dashboard')
        ->name('client-admin.live');

    Route::post('/services/support-devices/refresh', [ClientAdminDashboardController::class, 'refresh'])
        ->middleware(['can:view-client-admin-dashboard', 'throttle:6,1'])
        ->name('client-admin.refresh');

    Route::redirect('/client-admin', '/services/support-devices');
    Route::redirect('/client-admin/live', '/services/support-devices/live');

    Route::get('/client-admin/backups', [ClientBackupsController::class, 'index'])
        ->middleware('can:view-organisation-wide')
        ->name('client-admin.backups');

    Route::get('/security/huntress', [HuntressSecurityController::class, 'index'])
        ->middleware('can:view-huntress-security')
        ->name('security.huntress.index');

    Route::get('/security/huntress/cases/{incident}', [HuntressSecurityController::class, 'show'])
        ->middleware('can:view-huntress-security')
        ->name('security.huntress.show');

    Route::post('/security/huntress/refresh', [HuntressSecurityController::class, 'refresh'])
        ->middleware(['can:view-huntress-security', 'throttle:6,1'])
        ->name('security.huntress.refresh');

    Route::prefix('admin')
        ->name('admin.')
        ->middleware('role:'.implode(',', UserRole::adminRoles()))
        ->group(function () {
            Route::get('/', [AdminDashboardController::class, 'index'])->name('dashboard');
            Route::get('integration-health', [IntegrationHealthController::class, 'index'])
                ->name('integration-health.index');
            Route::get('integration-health/live', [IntegrationHealthController::class, 'live'])
                ->name('integration-health.live');
            Route::put('integration-health/freshness', [IntegrationHealthController::class, 'updateFreshness'])
                ->name('integration-health.freshness.update');

            Route::get('clients/{client}/microsoft-365', [ClientMicrosoft365DirectoryController::class, 'show'])
                ->name('clients.microsoft-365');
            Route::get('clients/{client}/microsoft-365/live', [ClientMicrosoft365DirectoryController::class, 'liveForClient'])
                ->name('clients.microsoft-365.live');
            Route::get('clients/{client}/microsoft-365/export', [ClientMicrosoft365DirectoryController::class, 'exportForClient'])
                ->name('clients.microsoft-365.export');
            Route::get('clients/{client}/security/huntress', [ClientHuntressSecurityController::class, 'showForClient'])
                ->name('clients.security.huntress');
            Route::get('clients/{client}/security/huntress/cases/{incident}', [ClientHuntressSecurityController::class, 'incidentForClient'])
                ->name('clients.security.huntress.show');
            Route::post('clients/{client}/security/huntress/refresh', [ClientHuntressSecurityController::class, 'refreshForClient'])
                ->middleware('throttle:6,1')
                ->name('clients.security.huntress.refresh');
            Route::get('clients/{client}/dropsuite', [ClientDropsuiteBackupController::class, 'show'])
                ->name('clients.dropsuite');
            Route::post('clients/{client}/dropsuite/refresh', [ClientDropsuiteBackupController::class, 'refresh'])
                ->middleware('throttle:6,1')
                ->name('clients.dropsuite.refresh');
            Route::get('graph-reconsent', [ClientController::class, 'graphReconsent'])
                ->name('clients.graph-reconsent');
            Route::resource('clients', ClientController::class)->except(['show']);
            Route::post('clients/{client}/sync-entra', [ClientController::class, 'syncEntra'])
                ->middleware('throttle:entra-sync')
                ->name('clients.sync-entra');
            Route::put('clients/{client}/onboarding', [ClientController::class, 'updateOnboarding'])
                ->name('clients.onboarding.update');
            Route::post('clients/{client}/bootstrap-entra', [ClientController::class, 'bootstrapEntra'])
                ->name('clients.bootstrap-entra');
            Route::post('clients/{client}/apply-scim', [ClientController::class, 'applyScim'])
                ->middleware('throttle:10,1')
                ->name('clients.apply-scim');
            Route::post('clients/{client}/retry-scim-export', [ClientController::class, 'retryScimExport'])
                ->middleware('throttle:10,1')
                ->name('clients.retry-scim-export');
            Route::post('clients/{client}/apply-client-sso', [ClientController::class, 'applyClientSso'])
                ->middleware('throttle:10,1')
                ->name('clients.apply-client-sso');
            Route::get('clients/{client}/users', [UserController::class, 'forClient'])
                ->name('clients.users.index');
            Route::get('users', [UserController::class, 'index'])->name('users.index');
            Route::resource('users', UserController::class)->except(['show', 'index']);
            Route::middleware('role:'.UserRole::SuperAdmin->value)->group(function () {
                Route::resource('team', TeamController::class)
                    ->except(['show'])
                    ->parameters(['team' => 'user']);
            });
            Route::resource('portal-links', PortalLinkController::class)->except(['show']);
            Route::resource('notices', NoticeController::class)->except(['show']);
            Route::resource('recommendations', RecommendationController::class)->except(['show']);
            Route::resource('opportunities', OpportunityController::class)->except(['show']);

            Route::get('settings', [SettingController::class, 'index'])->name('settings.index');
            Route::put('settings', [SettingController::class, 'update'])->name('settings.update');

            Route::get('activity-logs', [ActivityLogController::class, 'index'])->name('activity-logs.index');
        });
});
