<?php

namespace App\Http\Controllers;

use App\Services\Portal\ClientVisibilityService;
use App\Services\SuperOps\SuperOpsClientMetricsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Support & Devices - SuperOps tickets and managed devices,
 * scoped by ClientVisibilityService (admin = org-wide, user = personal tickets).
 */
class ClientAdminDashboardController extends Controller
{
    public function __construct(
        private SuperOpsClientMetricsService $superOps,
        private ClientVisibilityService $visibility,
    ) {}

    public function index(Request $request): View
    {
        $user = $request->user();
        abort_unless($user->canViewClientAdminDashboard(), 403);

        $client = $user->client;
        abort_unless($client && $this->visibility->canAccessClientSystems($user, $client), 404);

        return view('client-admin.dashboard', $this->pageData($request, $client));
    }

    public function live(Request $request): View
    {
        $user = $request->user();
        abort_unless($user->canViewClientAdminDashboard(), 403);

        $client = $user->client;
        abort_unless($client && $this->visibility->canAccessClientSystems($user, $client), 404);

        return view('client-admin._dashboard-live-root', $this->pageData($request, $client));
    }

    public function refresh(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->canViewClientAdminDashboard(), 403);

        $client = $user->client;
        abort_unless($client && $this->visibility->canAccessClientSystems($user, $client), 404);
        abort_unless($this->visibility->canViewOrganisationWide($user, $client), 403);

        $queued = $this->superOps->queueRefresh($client, respectCooldown: true);

        return redirect()
            ->route('client-admin.dashboard')
            ->with(
                $queued ? 'success' : 'error',
                $queued
                    ? 'Support refresh queued. Numbers on this page update when ready - no full reload.'
                    : 'Please wait before refreshing again.',
            );
    }

    /**
     * @return array<string, mixed>
     */
    private function pageData(Request $request, \App\Models\Client $client): array
    {
        $user = $request->user();

        return [
            'client' => $client,
            'summary' => $this->superOps->summaryForClient($client, false, $user),
            'organisationWide' => $this->visibility->canViewOrganisationWide($user, $client),
        ];
    }
}
