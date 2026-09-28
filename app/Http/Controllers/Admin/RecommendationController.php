<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreRecommendationRequest;
use App\Http\Requests\Admin\UpdateRecommendationRequest;
use App\Models\Client;
use App\Models\ClientRecommendation;
use App\Services\ActivityLogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class RecommendationController extends Controller
{
    public function __construct(
        private ActivityLogService $activityLog,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', ClientRecommendation::class);

        $clientIds = $request->user()->accessibleClientIds();

        $recommendations = ClientRecommendation::query()
            ->with(['client', 'creator'])
            ->whereIn('client_id', $clientIds)
            ->orderBy('display_order')
            ->paginate(15);

        return view('admin.recommendations.index', compact('recommendations'));
    }

    public function create(Request $request): View
    {
        $this->authorize('create', ClientRecommendation::class);

        $clientIds = $request->user()->accessibleClientIds();
        $clients = Client::whereIn('id', $clientIds)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        return view('admin.recommendations.create', compact('clients'));
    }

    public function store(StoreRecommendationRequest $request): RedirectResponse
    {
        $this->authorize('create', ClientRecommendation::class);

        $recommendation = ClientRecommendation::create([
            ...$request->validated(),
            'created_by' => $request->user()->id,
        ]);

        $this->activityLog->log('recommendation.created', $recommendation, clientId: $recommendation->client_id);

        return redirect()->route('admin.recommendations.index')
            ->with('success', 'Recommendation created successfully.');
    }

    public function edit(ClientRecommendation $recommendation): View
    {
        $this->authorize('update', $recommendation);

        $clientIds = request()->user()->accessibleClientIds();
        $clients = Client::whereIn('id', $clientIds)
            ->orderBy('name')
            ->get();

        return view('admin.recommendations.edit', compact('recommendation', 'clients'));
    }

    public function update(UpdateRecommendationRequest $request, ClientRecommendation $recommendation): RedirectResponse
    {
        $this->authorize('update', $recommendation);

        $recommendation->update($request->validated());

        $this->activityLog->log('recommendation.updated', $recommendation, clientId: $recommendation->client_id);

        return redirect()->route('admin.recommendations.index')
            ->with('success', 'Recommendation updated successfully.');
    }

    public function destroy(ClientRecommendation $recommendation): RedirectResponse
    {
        $this->authorize('delete', $recommendation);

        $this->activityLog->log('recommendation.deleted', $recommendation, clientId: $recommendation->client_id);

        $recommendation->delete();

        return redirect()->route('admin.recommendations.index')
            ->with('success', 'Recommendation deleted successfully.');
    }
}
