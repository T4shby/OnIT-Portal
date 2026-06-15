<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ActivityLogController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', ActivityLog::class);

        $clientIds = $request->user()->accessibleClientIds();

        $logs = ActivityLog::query()
            ->with(['user', 'client'])
            ->when(! empty($clientIds), fn ($q) => $q->whereIn('client_id', $clientIds))
            ->latest()
            ->paginate(15);

        return view('admin.activity-logs.index', compact('logs'));
    }
}
