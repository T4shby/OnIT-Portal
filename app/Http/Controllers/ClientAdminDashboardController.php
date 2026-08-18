<?php

namespace App\Http\Controllers;

use App\Services\Portal\ClientVisibilityService;
use App\Services\Portal\DashboardFeedRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Organisation overview - metrics from DashboardFeedRegistry,
 * scoped by ClientVisibilityService (admin = org-wide, user = personal).
 */
class ClientAdminDashboardController extends Controller
{
    public function __construct(
        private DashboardFeedRegistry $feeds,
        private ClientVisibilityService $visibility,
    ) {}

    public function index(Request $request): View
    {
        $user = $request->user();
        abort_unless($user->canViewClientAdminDashboard(), 403);

        $client = $user->client;
        abort_unless($client && $this->visibility->canAccessClientSystems($user, $client), 404);

        return view('client-admin.dashboard', $this->dashboardData($request, $client));
    }

    public function live(Request $request): View
    {
        $user = $request->user();
        abort_unless($user->canViewClientAdminDashboard(), 403);

        $client = $user->client;
        abort_unless($client && $this->visibility->canAccessClientSystems($user, $client), 404);

        return view('client-admin._dashboard-live-root', $this->dashboardData($request, $client));
    }

    public function refresh(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->canViewClientAdminDashboard(), 403);

        $client = $user->client;
        abort_unless($client && $this->visibility->canAccessClientSystems($user, $client), 404);
        abort_unless($this->visibility->canViewOrganisationWide($user, $client), 403);

        $queued = $this->feeds->queueOverviewRefresh($client, respectCooldown: true);

        return redirect()
            ->route('client-admin.dashboard')
            ->with(
                $queued ? 'success' : 'error',
                $queued
                    ? 'Dashboard refresh queued. Numbers on this page update when ready - no full reload.'
                    : 'Please wait before refreshing again.',
            );
    }

    /**
     * @return array<string, mixed>
     */
    private function dashboardData(Request $request, \App\Models\Client $client): array
    {
        $user = $request->user();
        $summaries = $this->feeds->summariesForClient($client, false, $user);
        $orgWide = $this->visibility->canViewOrganisationWide($user, $client);

        return array_merge(
            [
                'client' => $client,
                'dashboardFeeds' => $this->feeds,
                'feedSummaries' => $summaries['by_key'],
                'organisationWide' => $orgWide,
            ],
            $summaries['view'],
        );
    }
}
