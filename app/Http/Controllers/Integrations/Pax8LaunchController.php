<?php

namespace App\Http\Controllers\Integrations;

use App\Http\Controllers\Controller;
use App\Services\ActivityLogService;
use App\Services\Pax8\Pax8SsoService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class Pax8LaunchController extends Controller
{
    public function __construct(
        private Pax8SsoService $sso,
        private ActivityLogService $activityLog,
    ) {}

    public function __invoke(Request $request): RedirectResponse
    {
        $user = $request->user();

        if (! $this->sso->isEnabledForUser($user)) {
            return redirect()->route('dashboard')->with('error', $this->sso->accessDeniedHint($user));
        }

        $this->activityLog->log('pax8.sso_launch', null, [], clientId: $user->client_id);

        return redirect()->away($this->sso->launchUrlFor($user));
    }
}
