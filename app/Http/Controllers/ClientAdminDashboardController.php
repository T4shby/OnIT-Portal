<?php

namespace App\Http\Controllers;

use App\Services\SuperOps\SuperOpsClientMetricsService;
use App\Services\SuperOps\SuperOpsSsoService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ClientAdminDashboardController extends Controller
{
    public function __construct(
        private SuperOpsClientMetricsService $metrics,
    ) {}

    public function index(Request $request): View
    {
        $user = $request->user();

        abort_unless($user->canViewClientAdminDashboard(), 403);

        $client = $user->client;
        abort_unless($client, 404);

        $summary = $this->metrics->summaryForClient($client);

        return view('client-admin.dashboard', [
            'client' => $client,
            'summary' => $summary,
        ]);
    }

    public function refresh(Request $request): RedirectResponse
    {
        $user = $request->user();

        abort_unless($user->canViewClientAdminDashboard(), 403);

        $client = $user->client;
        abort_unless($client, 404);

        $queued = $this->metrics->queueRefresh($client, respectCooldown: true);

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
