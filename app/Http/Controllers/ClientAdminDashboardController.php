<?php

namespace App\Http\Controllers;

use App\Services\Dropsuite\DropsuiteClientMetricsService;
use App\Services\Huntress\HuntressClientMetricsService;
use App\Services\M365\M365InsightsService;
use App\Services\SuperOps\SuperOpsClientMetricsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ClientAdminDashboardController extends Controller
{
    public function __construct(
        private SuperOpsClientMetricsService $metrics,
        private M365InsightsService $m365Insights,
        private HuntressClientMetricsService $huntressMetrics,
        private DropsuiteClientMetricsService $dropsuiteMetrics,
    ) {}

    public function index(Request $request): View
    {
        $user = $request->user();

        abort_unless($user->canViewClientAdminDashboard(), 403);

        $client = $user->client;
        abort_unless($client, 404);

        return view('client-admin.dashboard', [
            'client' => $client,
            'summary' => $this->metrics->summaryForClient($client),
            'm365Insights' => $this->m365Insights->summaryForClient($client),
            'huntressSummary' => $this->huntressMetrics->summaryForClient($client),
            'dropsuiteSummary' => $this->dropsuiteMetrics->summaryForClient($client),
        ]);
    }

    public function refresh(Request $request): RedirectResponse
    {
        $user = $request->user();

        abort_unless($user->canViewClientAdminDashboard(), 403);

        $client = $user->client;
        abort_unless($client, 404);

        $queued = $this->metrics->queueRefresh($client, respectCooldown: true)
            || $this->m365Insights->queueRefresh($client, respectCooldown: true)
            || $this->huntressMetrics->queueRefresh($client, respectCooldown: true)
            || $this->dropsuiteMetrics->queueRefresh($client, respectCooldown: true);

        return redirect()
            ->route('client-admin.dashboard')
            ->with(
                $queued ? 'success' : 'error',
                $queued
                    ? 'Dashboard refresh queued.'
                    : 'Please wait before refreshing again.',
            );
    }
}
