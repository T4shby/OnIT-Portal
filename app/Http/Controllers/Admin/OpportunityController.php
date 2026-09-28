<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreOpportunityRequest;
use App\Http\Requests\Admin\UpdateOpportunityRequest;
use App\Models\Client;
use App\Models\ClientOpportunity;
use App\Services\ActivityLogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class OpportunityController extends Controller
{
    public function __construct(
        private ActivityLogService $activityLog,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', ClientOpportunity::class);

        $clientIds = $request->user()->accessibleClientIds();

        $opportunities = ClientOpportunity::query()
            ->with(['client', 'creator'])
            ->whereIn('client_id', $clientIds)
            ->orderBy('display_order')
            ->paginate(15);

        return view('admin.opportunities.index', compact('opportunities'));
    }

    public function create(Request $request): View
    {
        $this->authorize('create', ClientOpportunity::class);

        $clientIds = $request->user()->accessibleClientIds();
        $clients = Client::whereIn('id', $clientIds)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        return view('admin.opportunities.create', compact('clients'));
    }

    public function store(StoreOpportunityRequest $request): RedirectResponse
    {
        $this->authorize('create', ClientOpportunity::class);

        $opportunity = ClientOpportunity::create([
            ...$request->validated(),
            'created_by' => $request->user()->id,
        ]);

        $this->activityLog->log('opportunity.created', $opportunity, clientId: $opportunity->client_id);

        return redirect()->route('admin.opportunities.index')
            ->with('success', 'Opportunity created successfully.');
    }

    public function edit(ClientOpportunity $opportunity): View
    {
        $this->authorize('update', $opportunity);

        $clientIds = request()->user()->accessibleClientIds();
        $clients = Client::whereIn('id', $clientIds)
            ->orderBy('name')
            ->get();

        return view('admin.opportunities.edit', compact('opportunity', 'clients'));
    }

    public function update(UpdateOpportunityRequest $request, ClientOpportunity $opportunity): RedirectResponse
    {
        $this->authorize('update', $opportunity);

        $opportunity->update($request->validated());

        $this->activityLog->log('opportunity.updated', $opportunity, clientId: $opportunity->client_id);

        return redirect()->route('admin.opportunities.index')
            ->with('success', 'Opportunity updated successfully.');
    }

    public function destroy(ClientOpportunity $opportunity): RedirectResponse
    {
        $this->authorize('delete', $opportunity);

        $this->activityLog->log('opportunity.deleted', $opportunity, clientId: $opportunity->client_id);

        $opportunity->delete();

        return redirect()->route('admin.opportunities.index')
            ->with('success', 'Opportunity deleted successfully.');
    }
}
