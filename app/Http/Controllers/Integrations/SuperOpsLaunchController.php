<?php

namespace App\Http\Controllers\Integrations;

use App\Http\Controllers\Controller;
use App\Services\ActivityLogService;
use App\Services\SuperOps\SuperOpsSsoService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SuperOpsLaunchController extends Controller
{
    public function __construct(
        private SuperOpsSsoService $sso,
        private ActivityLogService $activityLog,
    ) {}

    public function __invoke(Request $request): RedirectResponse
    {
        $user = $request->user();

        if (! $this->sso->isEnabledForUser($user)) {
            return redirect()->route('dashboard')->with('error', $this->sso->accessDeniedHint($user));
        }

        $this->sso->establishSsoSession($user);
        $this->activityLog->log('superops.sso_launch', null, [], clientId: $user->client_id);

        return redirect()->away($this->sso->launchUrlFor($user));
    }
}
