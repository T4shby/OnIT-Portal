<?php

namespace App\Http\Controllers;

use App\Services\Portal\DashboardFeedRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Client Organisation overview — metrics composed from DashboardFeedRegistry
 * (SuperOps, Huntress, Dropsuite, M365 insights).
 */
class ClientAdminDashboardController extends Controller
{
    public function __construct(
        private DashboardFeedRegistry $feeds,
    ) {}

    public function index(Request $request): View
    {
        $user = $request->user();

        abort_unless($user->canViewClientAdminDashboard(), 403);

        $client = $user->client;
        abort_unless($client, 404);

        return view('client-admin.dashboard', $this->dashboardData($client));
    }

    /**
     * HTML fragment for live metric updates (no full page reload).
     */
    public function live(Request $request): View
    {
        $user = $request->user();

        abort_unless($user->canViewClientAdminDashboard(), 403);

        $client = $user->client;
        abort_unless($client, 404);

        return view('client-admin._dashboard-live-root', $this->dashboardData($client));
    }

    public function refresh(Request $request): RedirectResponse
    {
        $user = $request->user();

        abort_unless($user->canViewClientAdminDashboard(), 403);

        $client = $user->client;
        abort_unless($client, 404);

        $queued = $this->feeds->queueOverviewRefresh($client, respectCooldown: true);

        return redirect()
            ->route('client-admin.dashboard')
            ->with(
                $queued ? 'success' : 'error',
                $queued
                    ? 'Dashboard refresh queued. Numbers on this page update when ready — no full reload.'
                    : 'Please wait before refreshing again.',
            );
    }

    /**
     * @return array<string, mixed>
     */
    private function dashboardData(\App\Models\Client $client): array
    {
        $summaries = $this->feeds->summariesForClient($client);

        return array_merge(
            [
                'client' => $client,
                'dashboardFeeds' => $this->feeds,
                'feedSummaries' => $summaries['by_key'],
            ],
            $summaries['view'],
        );
    }
}
