<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreClientRequest;
use App\Http\Requests\Admin\UpdateClientOnboardingRequest;
use App\Http\Requests\Admin\UpdateClientRequest;
use App\Models\Client;
use App\Services\ActivityLogService;
use App\Services\ClientOnboardingService;
use App\Services\EntraSync\EntraGroupSyncService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class ClientController extends Controller
{
    public function __construct(
        private ActivityLogService $activityLog,
        private ClientOnboardingService $onboarding,
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

        return view('admin.clients.create', [
            'client' => new Client,
            'fieldHelps' => $this->onboarding->fieldHelps(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function onboardingViewData(Client $client): array
    {
        return [
            'client' => $client,
            'onboardingSteps' => $this->onboarding->steps($client),
            'onboardingProgress' => $this->onboarding->progress($client),
            'adminConsentUrl' => $this->onboarding->adminConsentUrl($client),
            'fieldHelps' => $this->onboarding->fieldHelps(),
        ];
    }

    public function store(StoreClientRequest $request): RedirectResponse
    {
        $this->authorize('create', Client::class);

        $client = Client::create([
            'name' => $request->name,
            'slug' => Str::slug($request->name),
            'superops_account_id' => $request->superops_account_id,
            'superops_sso_enabled' => $request->boolean('superops_sso_enabled'),
            'pax8_company_id' => $request->pax8_company_id,
            'pax8_sso_enabled' => $request->boolean('pax8_sso_enabled'),
            'entra_tenant_id' => $request->entra_tenant_id,
            'entra_group_id' => $request->entra_group_id,
            'entra_superops_app_id' => $request->entra_superops_app_id,
            'entra_sync_enabled' => $request->boolean('entra_sync_enabled'),
            'is_active' => $request->boolean('is_active', true),
        ]);

        $this->activityLog->log('client.created', $client, clientId: $client->id);

        return redirect()->route('admin.clients.edit', $client)
            ->with('success', 'Client saved. Use Update on the left to change fields; the setup guide on the right tracks progress.');
    }

    public function edit(Client $client): View
    {
        $this->authorize('update', $client);

        $this->onboarding->syncAutoCheckpointsFromClient($client);
        $client->refresh();

        return view('admin.clients.edit', $this->onboardingViewData($client));
    }

    public function update(UpdateClientRequest $request, Client $client): RedirectResponse
    {
        $this->authorize('update', $client);

        $client->update([
            'name' => $request->name,
            'slug' => Str::slug($request->name),
            'superops_account_id' => $request->superops_account_id,
            'superops_sso_enabled' => $request->boolean('superops_sso_enabled'),
            'pax8_company_id' => $request->pax8_company_id,
            'pax8_sso_enabled' => $request->boolean('pax8_sso_enabled'),
            'entra_tenant_id' => $request->entra_tenant_id,
            'entra_group_id' => $request->entra_group_id,
            'entra_superops_app_id' => $request->entra_superops_app_id,
            'entra_sync_enabled' => $request->boolean('entra_sync_enabled'),
            'is_active' => $request->boolean('is_active'),
        ]);

        $client->refresh();
        $this->onboarding->syncAutoCheckpointsFromClient($client);

        $this->activityLog->log('client.updated', $client, clientId: $client->id);

        $message = 'Client updated successfully.';

        if (filled($client->entra_group_id)) {
            $message .= ' Security group step (04) is complete — Entra group ID is saved.';
        }

        return redirect()->route('admin.clients.edit', $client)
            ->with('success', $message);
    }

    public function syncEntra(Client $client, EntraGroupSyncService $sync): RedirectResponse
    {
        $this->authorize('update', $client);

        $dryRun = request()->boolean('dry_run');
        $result = $sync->syncClient($client, $dryRun);

        if ($result->failed()) {
            return back()->with('error', $result->errors[0] ?? 'Entra sync failed.');
        }

        $message = $result->summary($dryRun);

        if ($result->hasErrors()) {
            $warnings = array_slice($result->errors, 0, 3);
            $message .= ' Warnings: '.implode(' ', $warnings);

            if (count($result->errors) > 3) {
                $message .= ' ('.count($result->errors).' warnings total)';
            }
        }

        $this->activityLog->log(
            $dryRun ? 'client.entra_sync_dry_run' : 'client.entra_synced',
            $client,
            properties: [
                'created' => $result->created,
                'updated' => $result->updated,
                'deactivated' => $result->deactivated,
                'skipped' => $result->skipped,
            ],
            clientId: $client->id,
        );

        return back()->with('success', ucfirst($message));
    }

    public function updateOnboarding(UpdateClientOnboardingRequest $request, Client $client): RedirectResponse
    {
        $this->authorize('update', $client);

        $this->onboarding->updateChecklist($client, $request->validated('checkpoints'));
        $client->refresh();

        $this->activityLog->log(
            'client.onboarding_updated',
            $client,
            properties: $request->validated('checkpoints'),
            clientId: $client->id,
        );

        return redirect()->route('admin.clients.edit', $client)
            ->with('success', 'Setup checklist saved.');
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
