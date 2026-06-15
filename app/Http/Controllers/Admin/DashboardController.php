<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\ClientNotice;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
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

        return view('admin.dashboard', compact('stats', 'recentActivity'));
    }
}
