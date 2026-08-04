<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ApplyClientSsoSamlRequest;
use App\Http\Requests\Admin\ApplySuperOpsScimRequest;
use App\Http\Requests\Admin\StoreClientRequest;
use App\Http\Requests\Admin\UpdateClientOnboardingRequest;
use App\Http\Requests\Admin\UpdateClientRequest;
use App\Jobs\SyncEntraClientJob;
use App\Models\Client;
use App\Services\ActivityLogService;
use App\Services\ClientOnboardingService;
use App\Services\EntraSync\EntraGroupSyncService;
use App\Services\EntraSync\EntraSyncResult;
use App\Services\SuperOps\SuperOpsClientMetricsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\View\View;

class ClientController extends Controller
{
    public function __construct(
        private ActivityLogService $activityLog,
        private ClientOnboardingService $onboarding,
        private SuperOpsClientMetricsService $superOpsMetrics,
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
            'fieldHelps' => $this->onboarding->fieldHelps($client),
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
            'dropsuite_organization_id' => $request->dropsuite_organization_id,
            'huntress_organization_id' => $request->huntress_organization_id,
            'entra_tenant_id' => null,
            'entra_license_tier' => ClientOnboardingService::ENTRA_LICENSE_FREE,
            'entra_group_id' => null,
            'entra_superops_app_id' => null,
            'entra_superops_sso_app_id' => null,
            'entra_sync_enabled' => false,
            'is_active' => $request->boolean('is_active', true),
        ]);

        $this->activityLog->log('client.created', $client, clientId: $client->id);

        if ($this->superOpsMetrics->needsColdPrewarm($client)) {
            $this->superOpsMetrics->queueRefresh($client);
        }

        return redirect()->route('admin.clients.edit', $client)
            ->with('success', 'Client created. Start with step 01 on the right.');
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
            'dropsuite_organization_id' => $request->dropsuite_organization_id,
            'huntress_organization_id' => $request->huntress_organization_id,
            'entra_tenant_id' => $request->entra_tenant_id,
            'entra_license_tier' => $request->input('entra_license_tier', ClientOnboardingService::ENTRA_LICENSE_FREE),
            'entra_group_id' => $request->entra_group_id,
            'entra_superops_app_id' => $request->entra_superops_app_id,
            'entra_superops_sso_app_id' => $request->entra_superops_sso_app_id,
            'entra_sync_enabled' => $request->boolean('entra_sync_enabled'),
            'is_active' => $request->boolean('is_active'),
        ]);

        $client->refresh();
        $this->onboarding->syncAutoCheckpointsFromClient($client);

        $this->activityLog->log('client.updated', $client, clientId: $client->id);

        // Keep SuperOps dashboard filled once the client is linked — no need for a first visit.
        if ($this->superOpsMetrics->needsColdPrewarm($client)) {
            $this->superOpsMetrics->queueRefresh($client);
        }

        $message = 'Client updated successfully.';

        if ($client->entra_license_tier === ClientOnboardingService::ENTRA_LICENSE_P1 && filled($client->entra_group_id)) {
            $message .= ' Step 03 is complete — Entra tenant ID and group ID are saved.';
        } elseif (($client->entra_license_tier ?? ClientOnboardingService::ENTRA_LICENSE_FREE) === ClientOnboardingService::ENTRA_LICENSE_FREE
            && filled($client->entra_tenant_id) && filled($client->entra_group_id)) {
            $message .= ' Step 03 is complete — Entra tenant ID and group ID are saved.';
        }

        return redirect()->route('admin.clients.edit', $client)
            ->with('success', $message);
    }

    public function syncEntra(Client $client, EntraGroupSyncService $sync): RedirectResponse
    {
        $this->authorize('update', $client);

        $dryRun = request()->boolean('dry_run');

        if (! config('services.entra_sync.enabled')) {
            return back()->with('error', 'Entra sync is disabled (ENTRA_SYNC_ENABLED=false).');
        }

        if ($dryRun) {
            SyncEntraClientJob::dispatch($client->id, dryRun: true);

            return back()->with(
                'success',
                'Dry run started in the background. Refresh this page in 1–2 minutes; the latest result is stored for this client.',
            );
        }

        $lock = Cache::lock(
            'entra_sync.client.'.$client->id,
            (int) config('services.entra_sync.lock_seconds', 600),
        );

        if (! $lock->get()) {
            return back()->with(
                'error',
                'Entra sync is already running for this client. Wait 1–2 minutes and refresh, or run: php artisan portal:release-entra-sync-lock '.$client->id,
            );
        }

        $lock->release();

        SyncEntraClientJob::dispatch($client->id);

        return back()->with(
            'success',
            'Entra sync started in the background. Refresh this page in 1–2 minutes to see Last synced update. Check Entra provisioning logs for each user.',
        );
    }

    private function finishEntraSyncResponse(Client $client, EntraSyncResult $result, bool $dryRun): RedirectResponse
    {
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
                'warnings' => count($result->errors),
            ],
            clientId: $client->id,
        );

        $flashKey = $result->hasWarnings() ? 'warning' : 'success';

        return back()->with($flashKey, ucfirst($message));
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

    public function bootstrapEntra(Client $client): RedirectResponse
    {
        $this->authorize('update', $client);

        if (! filled($client->entra_tenant_id)) {
            return redirect()->route('admin.clients.edit', $client)
                ->with('error', 'No tenant ID yet. Use Connect Microsoft tenant first.');
        }

        $result = app(\App\Services\EntraSync\CustomerEntraBootstrapService::class)
            ->bootstrap($client, $client->entra_tenant_id);

        $this->activityLog->log(
            'client.entra_bootstrap',
            $client,
            properties: [
                'ok' => $result['ok'],
                'details' => $result['details'],
                'warnings' => $result['warnings'],
            ],
            clientId: $client->id,
        );

        $message = $result['summary'];
        if ($result['details'] !== []) {
            $message .= ' '.implode(' · ', array_slice($result['details'], 0, 6));
        }

        $redirect = redirect()->route('admin.clients.edit', $client)
            ->with($result['ok'] ? 'success' : 'error', $message);

        if ($result['warnings'] !== []) {
            $redirect = $redirect->with('warning', implode(' ', array_slice($result['warnings'], 0, 4)));
        }

        return $redirect;
    }

    public function applyScim(ApplySuperOpsScimRequest $request, Client $client): RedirectResponse
    {
        $this->authorize('update', $client);

        if (! filled($client->entra_tenant_id) || ! filled($client->entra_superops_app_id)) {
            return redirect()->route('admin.clients.edit', $client)
                ->with('error', 'Connect Microsoft first so Tenant ID and SuperOps SCIM Application (client) ID are saved.');
        }

        try {
            $result = app(\App\Services\EntraSync\MicrosoftGraphClient::class)
                ->applySuperOpsScimCredentials(
                    $client->entra_tenant_id,
                    $client->entra_superops_app_id,
                    $request->validated('scim_tenant_url'),
                    $request->validated('scim_secret_token'),
                );
        } catch (\Throwable $e) {
            report($e);

            return redirect()->route('admin.clients.edit', $client)
                ->withInput($request->except('scim_secret_token'))
                ->with('error', 'Could not push SCIM credentials to Entra: '.$e->getMessage());
        }

        $this->onboarding->updateChecklist($client, [
            'superops_scim_tokens' => true,
            'superops_scim_app' => true,
            'superops_scim_provisioning' => true,
        ]);

        // Write extensionAttribute1 + provision-on-demand so SuperOps last names include mailbox type suffixes.
        $syncQueued = false;
        if (config('services.entra_sync.enabled') && filled($client->entra_tenant_id) && filled($client->entra_group_id)) {
            if (! $client->entra_sync_enabled) {
                $client->update(['entra_sync_enabled' => true]);
                $client->refresh();
            }

            Cache::put('entra_sync.in_flight.'.$client->id, true, now()->addMinutes(15));
            SyncEntraClientJob::dispatch($client->id, dryRun: false);
            $syncQueued = true;
        }

        $this->activityLog->log(
            'client.scim_credentials_applied',
            $client,
            properties: [
                'job_id' => $result['jobId'],
                'started' => $result['started'],
                'name_mappings' => $result['nameMappingsConfigured'] ?? false,
                'sync_queued' => $syncQueued,
                'details' => $result['details'],
                'scim_host' => parse_url($request->validated('scim_tenant_url'), PHP_URL_HOST),
            ],
            clientId: $client->id,
        );

        $message = 'SuperOps SCIM credentials written to Entra';
        if ($result['nameMappingsConfigured'] ?? false) {
            $message .= ', SuperOps name mappings set (familyName ← extensionAttribute1)';
        }
        $message .= ', provisioning start requested.';
        if ($result['details'] !== []) {
            $message .= ' '.implode(' · ', array_slice($result['details'], 0, 6));
        }
        if ($syncQueued) {
            $message .= ' Portal Sync is running in the background. SuperOps Requester names update after that Sync and SCIM finish — usually a few minutes; refresh SuperOps then.';
        } else {
            $message .= ' Enable Entra sync and run Sync now so extensionAttribute1 is written and names update in SuperOps.';
        }

        $redirect = redirect()->route('admin.clients.edit', $client)
            ->with('success', $message)
            ->with('warning', 'Still required for Client SSO: step 08 Configure SAML (Entity ID + ACS) if not done.');

        if (! empty($result['warnings'])) {
            $redirect = $redirect->with('warning', implode(' ', array_slice($result['warnings'], 0, 3)));
        }

        return $redirect;
    }

    public function applyClientSso(ApplyClientSsoSamlRequest $request, Client $client): RedirectResponse
    {
        $this->authorize('update', $client);

        if (! filled($client->entra_tenant_id) || ! filled($client->entra_superops_sso_app_id)) {
            return redirect()->route('admin.clients.edit', $client)
                ->with('error', 'Connect Microsoft / bootstrap first so Client SSO Application (client) ID is saved.');
        }

        try {
            $result = app(\App\Services\EntraSync\MicrosoftGraphClient::class)
                ->applyClientSsoSamlConfiguration(
                    $client->entra_tenant_id,
                    $client->entra_superops_sso_app_id,
                    $request->validated('entity_id'),
                    $request->validated('consumer_service_url'),
                );
        } catch (\Throwable $e) {
            report($e);

            return redirect()->route('admin.clients.edit', $client)
                ->withInput()
                ->with('error', 'Could not configure Client SSO SAML in Entra: '.$e->getMessage());
        }

        cache()->put('client_sso_idp.'.$client->id, [
            'loginUrl' => $result['loginUrl'],
            'certificateBase64' => $result['certificateBase64'],
            'entityId' => $request->validated('entity_id'),
            'consumerServiceUrl' => $request->validated('consumer_service_url'),
            'configuredAt' => now()->toIso8601String(),
        ], now()->addDays(14));

        $this->onboarding->updateChecklist($client, [
            'superops_client_sso_configured' => true,
        ]);

        $this->activityLog->log(
            'client.client_sso_saml_configured',
            $client,
            properties: [
                'login_host' => parse_url($result['loginUrl'], PHP_URL_HOST),
                'details' => $result['details'],
                'warnings' => $result['warnings'],
            ],
            clientId: $client->id,
        );

        $message = 'Client SSO SAML configured in Entra. Copy Login URL + certificate below into SuperOps Client SSO and Save.';
        if ($result['details'] !== []) {
            $message .= ' '.implode(' · ', array_slice($result['details'], 0, 5));
        }

        $redirect = redirect()->route('admin.clients.edit', $client)
            ->with('success', $message)
            ->with('client_sso_login_url', $result['loginUrl'])
            ->with('client_sso_certificate', $result['certificateBase64']);

        if ($result['warnings'] !== []) {
            $redirect = $redirect->with('warning', implode(' ', array_slice($result['warnings'], 0, 3)));
        }

        return $redirect;
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
