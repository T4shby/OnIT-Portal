<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\ClientNotice;
use App\Models\User;
use App\Services\Admin\IntegrationHealthService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __construct(
        private IntegrationHealthService $integrationHealth,
    ) {}

    public function index(Request $request): View
    {
        $user = $request->user();
        $clientIds = $user->accessibleClientIds();

        $stats = [
            'clients' => Client::when(! empty($clientIds), fn ($q) => $q->whereIn('id', $clientIds))->count(),
            'users' => User::when(! empty($clientIds), fn ($q) => $q->whereIn('client_id', $clientIds))->count(),
            'notices' => ClientNotice::active()
                ->when(! empty($clientIds), fn ($q) => $q->whereIn('client_id', $clientIds))
                ->count(),
        ];

        $recentActivity = ActivityLog::with('user')
            ->when(! empty($clientIds), fn ($q) => $q->whereIn('client_id', $clientIds))
            ->latest()
            ->limit(10)
            ->get();

        $integrationHealth = $this->healthOverviewFor($request);

        return view('admin.dashboard', compact('stats', 'recentActivity', 'integrationHealth'));
    }

    /**
     * HTML fragment for live Integration Health polling (every ~5s on /admin).
     */
    public function integrationHealth(Request $request): View
    {
        return view('admin.partials.integration-health', [
            'integrationHealth' => $this->healthOverviewFor($request),
        ]);
    }

    /**
     * @return array{queue: array<string, mixed>, clients: list<array<string, mixed>>, stuck_count: int}
     */
    private function healthOverviewFor(Request $request): array
    {
        $clientIds = $request->user()->accessibleClientIds();

        return $this->integrationHealth->overview(
            empty($clientIds) ? null : $clientIds,
        );
    }
}
