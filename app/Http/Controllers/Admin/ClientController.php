<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ApplyClientSsoSamlRequest;
use App\Http\Requests\Admin\ApplySuperOpsScimRequest;
use App\Http\Requests\Admin\StoreClientRequest;
use App\Http\Requests\Admin\UpdateClientOnboardingRequest;
use App\Http\Requests\Admin\UpdateClientRequest;
use App\Jobs\ApplySuperOpsScimJob;
use App\Jobs\RepairSuperOpsScimExportJob;
use App\Jobs\SyncEntraClientJob;
use App\Models\Client;
use App\Services\ActivityLogService;
use App\Services\ClientOnboardingService;
use App\Services\EntraSync\EntraGroupSyncService;
use App\Services\EntraSync\EntraSyncResult;
use App\Services\EntraSync\MicrosoftGraphClient;
use App\Services\Portal\ClientHomeOverviewService;
use App\Services\Portal\ClientProductService;
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
        private ClientProductService $products,
        private ClientHomeOverviewService $homeOverview,
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

    /**
     * Staff batch: admin-consent Accept links for every client tenant with Graph linked.
     * Microsoft still requires a human Accept per tenant under GDAP - this only lists the URLs.
     */
    public function graphReconsent(Request $request): View
    {
        $this->authorize('viewAny', Client::class);

        $clientIds = $request->user()->accessibleClientIds();

        $clients = Client::query()
            ->when(! empty($clientIds), fn ($q) => $q->whereIn('id', $clientIds))
            ->whereNotNull('entra_tenant_id')
            ->where('entra_tenant_id', '!=', '')
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        $rows = $clients->map(function (Client $client) {
            return [
                'client' => $client,
                'url' => $this->onboarding->adminConsentUrl($client),
            ];
        })->filter(fn (array $row) => filled($row['url']));

        return view('admin.clients.graph-reconsent', [
            'rows' => $rows,
        ]);
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
            // What this customer’s /dashboard + Reports look like from sold products (no live numbers).
            'clientHomeComposition' => $this->homeOverview->staffHomeComposition($client),
            'scimProvisioningHealth' => $this->scimProvisioningHealth($client),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function scimProvisioningHealth(Client $client): ?array
    {
        if (! $client->exists
            || blank($client->entra_tenant_id)
            || blank($client->entra_superops_app_id)) {
            return null;
        }

        $cacheKey = 'scim.health.'.$client->id;

        return Cache::remember($cacheKey, now()->addMinutes(5), function () use ($client): array {
            return app(MicrosoftGraphClient::class)->getSuperOpsScimProvisioningHealth(
                (string) $client->entra_tenant_id,
                (string) $client->entra_superops_app_id,
            );
        });
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

        $productToggles = $request->input('products', []);
        if (is_array($productToggles) && $productToggles !== []) {
            $this->products->applyEntitlements($client, $productToggles);
        }
        $this->products->syncEntitlementsFromMappings($client->fresh());
        $client->refresh();

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

        $productToggles = $request->input('products', []);
        if (is_array($productToggles) && $productToggles !== []) {
            $this->products->applyEntitlements($client, $productToggles);
        }
        // Entitled when IDs saved even if toggle missed; does not re-entitle if staff set entitled=false.
        // Mapping-only: when entitled was unchecked we leave false; when product has ID and never toggled off earlier, backfill:
        $this->products->syncEntitlementsFromMappings($client->fresh());
        $client->refresh();
        $this->onboarding->syncAutoCheckpointsFromClient($client);

        $this->activityLog->log('client.updated', $client, clientId: $client->id);

        // Keep SuperOps dashboard filled once the client is linked - no need for a first visit.
        if ($this->superOpsMetrics->needsColdPrewarm($client)) {
            $this->superOpsMetrics->queueRefresh($client);
        }

        $message = 'Client updated successfully.';

        if ($client->entra_license_tier === ClientOnboardingService::ENTRA_LICENSE_P1 && filled($client->entra_group_id)) {
            $message .= ' Step 03 is complete - Entra tenant ID and group ID are saved.';
        } elseif (($client->entra_license_tier ?? ClientOnboardingService::ENTRA_LICENSE_FREE) === ClientOnboardingService::ENTRA_LICENSE_FREE
            && filled($client->entra_tenant_id) && filled($client->entra_group_id)) {
            $message .= ' Step 03 is complete - Entra tenant ID and group ID are saved.';
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
            SyncEntraClientJob::dispatchMarked($client->id, dryRun: true);

            return back()->with(
                'success',
                'Dry run started in the background. This Admin Dashboard updates live - Last synced appears when the job finishes.',
            );
        }

        $lock = Cache::lock(
            'entra_sync.client.'.$client->id,
            (int) config('services.entra_sync.lock_seconds', 600),
        );

        if (! $lock->get()) {
            return back()->with(
                'error',
                'Entra sync is already running for this client. Wait 1-2 minutes and refresh, or run: php artisan portal:release-entra-sync-lock '.$client->id,
            );
        }

        $lock->release();

        SyncEntraClientJob::dispatchMarked($client->id);

        return back()->with(
            'success',
            'Entra sync started in the background. Open Admin → Dashboard - Integration Health updates live while it runs.',
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

        $checkpoints = $request->validated('checkpoints');
        if (! is_array($checkpoints)) {
            $checkpoints = [];
        }

        $this->onboarding->updateChecklist($client, $checkpoints);
        $client->refresh();

        $this->activityLog->log(
            'client.onboarding_updated',
            $client,
            properties: ['checkpoints' => $checkpoints],
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

        // Graph waits (schema / already-exists) can exceed nginx's 60s gateway - never do that inline.
        ApplySuperOpsScimJob::markQueued($client->id);
        ApplySuperOpsScimJob::dispatch(
            $client->id,
            $request->validated('scim_tenant_url'),
            $request->validated('scim_secret_token'),
        );

        $this->activityLog->log(
            'client.scim_apply_queued',
            $client,
            properties: [
                'scim_host' => parse_url($request->validated('scim_tenant_url'), PHP_URL_HOST),
            ],
            clientId: $client->id,
        );

        return redirect()->route('admin.clients.edit', $client)
            ->with(
                'success',
                'Apply SCIM is running in the background (usually under 2 minutes). '
                .'You can leave this page - refresh step 07 until Done, or check the banner under Apply SCIM. '
                .'This avoids the previous 504 Gateway Time-out when Graph is slow.'
            );
    }

    public function retryScimExport(Client $client): RedirectResponse
    {
        $this->authorize('update', $client);

        if (! filled($client->entra_tenant_id) || ! filled($client->entra_superops_app_id)) {
            return redirect()->route('admin.clients.edit', $client)
                ->with('error', 'Connect Microsoft first so Tenant ID and SuperOps SCIM Application (client) ID are saved.');
        }

        RepairSuperOpsScimExportJob::markQueued($client->id);
        RepairSuperOpsScimExportJob::dispatch($client->id);

        $this->activityLog->log('client.scim_export_retry_queued', $client, clientId: $client->id);

        return redirect()->route('admin.clients.edit', $client)
            ->with(
                'success',
                'Retry SCIM export is running in the background (recreates Entra job, mappings, start, missing users, Sync). '
                .'Refresh in about a minute - Integration Health SuperOps SCIM should show Export active.'
            );
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
