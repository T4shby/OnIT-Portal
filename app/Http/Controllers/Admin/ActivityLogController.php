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

        // Staff-side events (team changes, settings, staff sign-ins) are logged with
        // client_id NULL, which whereIn() always excludes. Super admins must see the
        // whole audit trail; everyone else stays scoped to their clients.
        $logs = ActivityLog::query()
            ->with(['user', 'client'])
            ->unless($request->user()->can('manage-all-clients'), fn ($q) => $q->whereIn('client_id', $clientIds))
            ->latest()
            ->paginate(15);

        return view('admin.activity-logs.index', compact('logs'));
    }
}
