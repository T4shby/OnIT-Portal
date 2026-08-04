<?php

use App\Http\Controllers\Admin\ActivityLogController;
use App\Http\Controllers\Admin\ClientController;
use App\Http\Controllers\Admin\ClientMicrosoft365DirectoryController;
use App\Http\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Admin\NoticeController;
use App\Http\Controllers\Admin\OpportunityController;
use App\Http\Controllers\Admin\PortalLinkController;
use App\Http\Controllers\Admin\RecommendationController;
use App\Http\Controllers\Admin\SettingController;
use App\Http\Controllers\Admin\TeamController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Auth\MicrosoftAuthController;
use App\Http\Controllers\ClientAdminDashboardController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Microsoft365DirectoryController;
use App\Http\Controllers\Integrations\Pax8LaunchController;
use App\Http\Controllers\Integrations\SuperOpsLaunchController;
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

    Route::post('/microsoft-365/directory/refresh', [Microsoft365DirectoryController::class, 'refresh'])
        ->middleware(['can:view-m365-directory', 'throttle:6,1'])
        ->name('microsoft-365.directory.refresh');

    Route::get('/client-admin', [ClientAdminDashboardController::class, 'index'])
        ->middleware('can:view-client-admin-dashboard')
        ->name('client-admin.dashboard');

    Route::post('/client-admin/refresh', [ClientAdminDashboardController::class, 'refresh'])
        ->middleware(['can:view-client-admin-dashboard', 'throttle:6,1'])
        ->name('client-admin.refresh');

    Route::prefix('admin')
        ->name('admin.')
        ->middleware('role:'.implode(',', UserRole::adminRoles()))
        ->group(function () {
            Route::get('/', [AdminDashboardController::class, 'index'])->name('dashboard');

            Route::get('clients/{client}/microsoft-365', [ClientMicrosoft365DirectoryController::class, 'show'])
                ->name('clients.microsoft-365');
            Route::resource('clients', ClientController::class)->except(['show']);
            Route::post('clients/{client}/sync-entra', [ClientController::class, 'syncEntra'])
                ->middleware('throttle:entra-sync')
                ->name('clients.sync-entra');
            Route::put('clients/{client}/onboarding', [ClientController::class, 'updateOnboarding'])
                ->name('clients.onboarding.update');
            Route::post('clients/{client}/bootstrap-entra', [ClientController::class, 'bootstrapEntra'])
                ->name('clients.bootstrap-entra');
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
