<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreNoticeRequest;
use App\Http\Requests\Admin\UpdateNoticeRequest;
use App\Models\Client;
use App\Models\ClientNotice;
use App\Services\ActivityLogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class NoticeController extends Controller
{
    public function __construct(
        private ActivityLogService $activityLog,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', ClientNotice::class);

        $clientIds = $request->user()->accessibleClientIds();

        $notices = ClientNotice::query()
            ->with(['client', 'creator'])
            ->whereIn('client_id', $clientIds)
            ->latest()
            ->paginate(15);

        return view('admin.notices.index', compact('notices'));
    }

    public function create(Request $request): View
    {
        $this->authorize('create', ClientNotice::class);

        $clientIds = $request->user()->accessibleClientIds();
        $clients = Client::whereIn('id', $clientIds)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        return view('admin.notices.create', compact('clients'));
    }

    public function store(StoreNoticeRequest $request): RedirectResponse
    {
        $this->authorize('create', ClientNotice::class);

        $notice = ClientNotice::create([
            ...$request->validated(),
            'created_by' => $request->user()->id,
        ]);

        $this->activityLog->log('notice.created', $notice, clientId: $notice->client_id);

        return redirect()->route('admin.notices.index')
            ->with('success', 'Notice created successfully.');
    }

    public function edit(ClientNotice $notice): View
    {
        $this->authorize('update', $notice);

        $clientIds = request()->user()->accessibleClientIds();
        $clients = Client::whereIn('id', $clientIds)
            ->orderBy('name')
            ->get();

        return view('admin.notices.edit', compact('notice', 'clients'));
    }

    public function update(UpdateNoticeRequest $request, ClientNotice $notice): RedirectResponse
    {
        $this->authorize('update', $notice);

        $notice->update($request->validated());

        $this->activityLog->log('notice.updated', $notice, clientId: $notice->client_id);

        return redirect()->route('admin.notices.index')
            ->with('success', 'Notice updated successfully.');
    }

    public function destroy(ClientNotice $notice): RedirectResponse
    {
        $this->authorize('delete', $notice);

        $this->activityLog->log('notice.deleted', $notice, clientId: $notice->client_id);

        $notice->delete();

        return redirect()->route('admin.notices.index')
            ->with('success', 'Notice deleted successfully.');
    }
}
