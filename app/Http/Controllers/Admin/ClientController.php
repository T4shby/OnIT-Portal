<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreClientRequest;
use App\Http\Requests\Admin\UpdateClientRequest;
use App\Models\Client;
use App\Services\ActivityLogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class ClientController extends Controller
{
    public function __construct(
        private ActivityLogService $activityLog,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Client::class);

        $clientIds = $request->user()->accessibleClientIds();

        $clients = Client::query()
            ->when(! empty($clientIds), fn ($q) => $q->whereIn('id', $clientIds))
            ->withCount('users')
            ->latest()
            ->paginate(15);

        return view('admin.clients.index', compact('clients'));
    }

    public function create(): View
    {
        $this->authorize('create', Client::class);

        return view('admin.clients.create');
    }

    public function store(StoreClientRequest $request): RedirectResponse
    {
        $this->authorize('create', Client::class);

        $client = Client::create([
            'name' => $request->name,
            'slug' => Str::slug($request->name),
            'superops_account_id' => $request->superops_account_id,
            'superops_sso_enabled' => $request->boolean('superops_sso_enabled'),
            'is_active' => $request->boolean('is_active', true),
        ]);

        $this->activityLog->log('client.created', $client, clientId: $client->id);

        return redirect()->route('admin.clients.index')
            ->with('success', 'Client created successfully.');
    }

    public function edit(Client $client): View
    {
        $this->authorize('update', $client);

        return view('admin.clients.edit', compact('client'));
    }

    public function update(UpdateClientRequest $request, Client $client): RedirectResponse
    {
        $this->authorize('update', $client);

        $client->update([
            'name' => $request->name,
            'slug' => Str::slug($request->name),
            'superops_account_id' => $request->superops_account_id,
            'superops_sso_enabled' => $request->boolean('superops_sso_enabled'),
            'is_active' => $request->boolean('is_active'),
        ]);

        $this->activityLog->log('client.updated', $client, clientId: $client->id);

        return redirect()->route('admin.clients.index')
            ->with('success', 'Client updated successfully.');
    }

    public function destroy(Client $client): RedirectResponse
    {
        $this->authorize('delete', $client);

        $this->activityLog->log('client.deleted', $client, clientId: $client->id);

        $client->delete();

        return redirect()->route('admin.clients.index')
            ->with('success', 'Client deleted successfully.');
    }
}
