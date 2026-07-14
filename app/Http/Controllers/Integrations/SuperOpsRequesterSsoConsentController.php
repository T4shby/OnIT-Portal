<?php

namespace App\Http\Controllers\Integrations;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Services\ClientOnboardingService;
use App\Support\AdminConsentState;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class SuperOpsRequesterSsoConsentController extends Controller
{
    public function complete(Request $request): RedirectResponse|View
    {
        Log::info('SuperOps Requester SSO admin consent returned', [
            'admin_consent' => $request->query('admin_consent'),
            'tenant' => $request->query('tenant'),
            'error' => $request->query('error'),
            'error_description' => $request->query('error_description'),
            'state' => $request->query('state'),
        ]);

        if ($request->filled('error')) {
            return view('auth.superops-requester-sso-consent-complete', [
                'success' => false,
                'tenant' => $request->query('tenant'),
                'client' => $this->clientFromState($request->query('state')),
                'error' => (string) $request->query('error_description', $request->query('error')),
            ]);
        }

        $client = $this->clientFromState($request->query('state'));

        if ($client && $request->query('admin_consent') === 'True') {
            app(ClientOnboardingService::class)->updateChecklist($client, [
                'superops_client_sso_configured' => true,
            ]);
        }

        if (Auth::check() && $client && $request->query('admin_consent') === 'True') {
            return redirect()
                ->route('admin.clients.edit', $client)
                ->with(
                    'success',
                    'SuperOps Requester SSO accepted in the customer tenant. Verify Enterprise applications → SuperOps Requester SSO (On IT), then continue the checklist.',
                );
        }

        return view('auth.superops-requester-sso-consent-complete', [
            'success' => $request->query('admin_consent') === 'True',
            'tenant' => $request->query('tenant'),
            'client' => $client,
            'error' => null,
        ]);
    }

    private function clientFromState(mixed $state): ?Client
    {
        $clientId = AdminConsentState::decode(is_string($state) ? $state : null);

        return $clientId ? Client::find($clientId) : null;
    }
}
