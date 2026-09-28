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
            'clients' => Client::whereIn('id', $clientIds)->count(),
            'users' => User::whereIn('client_id', $clientIds)->count(),
            'notices' => ClientNotice::active()
                ->whereIn('client_id', $clientIds)
                ->count(),
        ];

        $recentActivity = ActivityLog::with('user')
            ->whereIn('client_id', $clientIds)
            ->latest()
            ->limit(10)
            ->get();

        $overview = $this->integrationHealth->overview(
            $clientIds,
        );

        $healthSummary = [
            'pending' => $overview['queue']['pending'] ?? 0,
            'stuck' => $overview['stuck_count'] ?? 0,
            'due' => $overview['due_count'] ?? 0,
            'aging' => $overview['aging_count'] ?? 0,
            'cold' => $overview['cold_count'] ?? 0,
        ];

        $productCoverage = $this->integrationHealth->productCoverage(
            $clientIds,
        );

        return view('admin.dashboard', compact('stats', 'recentActivity', 'healthSummary', 'productCoverage'));
    }
}
