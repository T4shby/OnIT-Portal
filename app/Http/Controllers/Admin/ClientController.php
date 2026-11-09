<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ApplyClientSsoSamlRequest;
use App\Http\Requests\Admin\ApplySuperOpsScimRequest;
use App\Http\Requests\Admin\StoreClientRequest;
use App\Http\Requests\Admin\UpdateClientOnboardingRequest;
use App\Http\Requests\Admin\UpdateClientRequest;
use App\Jobs\ApplyClientSsoSamlJob;
use App\Jobs\ApplySuperOpsScimJob;
use App\Jobs\BootstrapClientEntraJob;
use App\Jobs\RepairSuperOpsScimExportJob;
use App\Jobs\SyncEntraClientJob;
use App\Jobs\WarmClientOnboardingChecksJob;
use App\Models\Client;
use App\Models\ClientDomain;
use App\Models\ClientDomainDnsCheck;
use App\Services\ActivityLogService;
use App\Services\ClientOnboardingService;
use App\Services\EntraSync\EntraGroupSyncService;
use App\Services\MailDns\ClientDomainService;
use App\Services\MailDns\DkimSigningStatusService;
use App\Services\MailDns\EntraMailDomainSyncService;
use App\Services\Dropsuite\DropsuiteOrganizationDirectory;
use App\Services\Portal\ClientHomeOverviewService;
use App\Services\Portal\ClientProductService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ClientController extends Controller
{
    public function __construct(
        private ActivityLogService $activityLog,
        private ClientOnboardingService $onboarding,
        private ClientProductService $products,
        private ClientHomeOverviewService $homeOverview,
        private ClientDomainService $domains,
        private EntraMailDomainSyncService $entraDomains,
        private DropsuiteOrganizationDirectory $dropsuiteDirectory,
        private DkimSigningStatusService $dkimSigning,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Client::class);

        $clientIds = $request->user()->accessibleClientIds();

        $clients = Client::query()
            ->whereIn('id', $clientIds)
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
            ->whereIn('id', $clientIds)
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
            'dropsuiteOrganizations' => $this->dropsuiteOrganizationsForForms(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function onboardingViewData(Client $client): array
    {
        // Cache-only: the SCIM health (Graph) and SuperOps requester count
        // lookups are warmed by a queued job, never run on the page load.
        $this->queueOnboardingChecksWarmIfCold($client);

        $client->load('domains');

        return [
            'client' => $client,
            'onboardingSteps' => $this->onboarding->steps($client, live: false),
            'onboardingProgress' => $this->onboarding->progress($client, live: false),
            'adminConsentUrl' => $this->onboarding->adminConsentUrl($client),
            'fieldHelps' => $this->onboarding->fieldHelps($client),
            // What this customer’s /dashboard + Reports look like from sold products (no live numbers).
            'clientHomeComposition' => $this->homeOverview->staffHomeComposition($client),
            'scimProvisioningHealth' => $this->scimProvisioningHealth($client),
            'mailDomains' => $client->domains,
            'mailDnsStillRed' => $this->domains->anyDomainStillRed($client),
            'entraMailDomains' => $this->entraDomains->cached($client),
            'mailDnsHistory' => ClientDomainDnsCheck::query()
                ->where('client_id', $client->id)
                ->with(['domain', 'checkedBy'])
                ->orderByDesc('checked_at')
                ->limit(12)
                ->get(),
            'dropsuiteOrganizations' => $this->dropsuiteOrganizationsForForms(),
            'dkimSigning' => $this->dkimSigningForEdit($client),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function dkimSigningForEdit(Client $client): array
    {
        return $this->dkimSigning->cachedForClient($client);
    }

    /**
     * @return list<array{organization_id: string, name: string, user_count: int}>
     */
    private function dropsuiteOrganizationsForForms(): array
    {
        return $this->dropsuiteDirectory->organizations();
    }

    public function syncEntraDomains(Client $client): RedirectResponse
    {
        $this->authorize('update', $client);

        if (! filled($client->entra_tenant_id)) {
            return redirect()->route('admin.clients.edit', $client)
                ->with('error', 'Connect Microsoft first so the tenant ID is saved.');
        }

        // Prefer sync so the Edit page shows suggestions immediately after redirect.
        $result = $this->entraDomains->sync($client, importMissing: true, seedPrimaryIfEmpty: true);
        $this->dkimSigning->syncChecklist($client, $this->dkimSigning->summaryForClient($client->fresh(), fresh: true));
        $this->onboarding->syncAutoCheckpointsFromClient($client->fresh());
        $this->activityLog->log('client.entra_mail_domains_synced', $client, clientId: $client->id, properties: [
            'seeded_primary' => $result['seeded_primary'],
            'imported' => $result['imported'],
        ]);

        return redirect()
            ->to(route('admin.clients.edit', $client).'#mail-domains')
            ->with($result['ok'] ? 'success' : 'error', $result['summary']);
    }

    public function storeDomain(Request $request, Client $client): RedirectResponse
    {
        $this->authorize('update', $client);

        $validated = $request->validate([
            'domain' => ['required', 'string', 'max:255'],
            'make_primary' => ['sometimes', 'boolean'],
            'dkim_selectors' => ['nullable', 'string', 'max:500'],
        ]);

        $selectors = collect(preg_split('/[\s,;]+/', (string) ($validated['dkim_selectors'] ?? '')) ?: [])
            ->map(fn ($s) => strtolower(trim((string) $s)))
            ->filter()
            ->values()
            ->all();

        $row = $this->domains->addDomain(
            $client,
            (string) $validated['domain'],
            makePrimary: $request->boolean('make_primary'),
            dkimSelectors: $selectors,
        );
        // Sync so Edit does not flash "queued" then paint unchecked rows as red/pending.
        $this->domains->checkNow($row->fresh(), sync: true, userId: $request->user()?->id, source: 'manual');
        $this->onboarding->syncAutoCheckpointsFromClient($client->fresh());

        $this->activityLog->log('client.domain_added', $client, clientId: $client->id);

        return redirect()->route('admin.clients.edit', $client)
            ->with('success', 'Domain saved. DNS checked.');
    }

    public function checkDomain(Client $client, ClientDomain $domain): RedirectResponse
    {
        $this->authorize('update', $client);
        abort_unless($domain->client_id === $client->id, 404);

        $this->domains->checkNow($domain, sync: true, userId: request()->user()?->id, source: 'manual');
        $this->dkimSigning->syncChecklist($client, $this->dkimSigning->summaryForClient($client->fresh(), fresh: true));
        $this->onboarding->syncAutoCheckpointsFromClient($client->fresh());

        return redirect()->route('admin.clients.edit', $client)
            ->with('success', 'DNS checked for '.$domain->domain.'.');
    }

    public function refreshDkimSigning(Client $client): RedirectResponse
    {
        $this->authorize('update', $client);

        if (! filled($client->entra_tenant_id)) {
            return redirect()->to(route('admin.clients.edit', $client).'#mail-domains')
                ->with('error', 'Connect Microsoft first so Defender DKIM can be read.');
        }

        $summary = $this->dkimSigning->summaryForClient($client, fresh: true);
        $this->dkimSigning->syncChecklist($client, $summary);
        $this->onboarding->syncAutoCheckpointsFromClient($client->fresh());

        $message = ($summary['available'] ?? false)
            ? (
                ($summary['portal_custom_signing'] ?? false)
                    ? 'Microsoft reports DKIM signing Enabled and Valid for the portal mail domains.'
                    : 'Microsoft DKIM list updated. One or more portal domains are not yet Enabled / Valid in Defender.'
            )
            : (
                ($summary['reason'] ?? '') === 'dkim_signing_permission_missing'
                    ? 'Microsoft blocked the DKIM read. Add Exchange.ManageAsApp, re-consent, and assign Exchange Administrator to the portal app in this tenant.'
                    : 'Could not read Defender DKIM yet. Use Refresh after Graph / Exchange permissions are in place.'
            );

        return redirect()->to(route('admin.clients.edit', $client).'#mail-domains')
            ->with(($summary['available'] ?? false) ? 'success' : 'error', $message);
    }

    public function exportDomains(Client $client): StreamedResponse|RedirectResponse
    {
        $this->authorize('update', $client);

        $domains = $client->domains()->orderByDesc('is_primary')->orderBy('domain')->get();
        if ($domains->isEmpty()) {
            return redirect()->route('admin.clients.edit', $client)
                ->with('error', 'No mail domains to export yet.');
        }

        $filename = 'mail-dns-'.$client->slug.'-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($domains): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, [
                'domain',
                'is_primary',
                'provider',
                'overall',
                'overall_extended',
                'mx',
                'spf',
                'dkim',
                'dmarc',
                'autodiscover',
                'mta_sts',
                'tls_rpt',
                'bimi',
                'last_checked_at',
            ]);
            foreach ($domains as $domain) {
                $result = is_array($domain->last_result) ? $domain->last_result : [];
                $checks = is_array($result['checks'] ?? null) ? $result['checks'] : [];
                $band = static fn (string $key): string => (string) ($checks[$key]['band'] ?? '');
                fputcsv($out, [
                    $domain->domain,
                    $domain->is_primary ? 'yes' : 'no',
                    (string) ($result['provider']['label'] ?? ''),
                    (string) ($result['overall'] ?? ''),
                    (string) ($result['overall_extended'] ?? ''),
                    $band('mx'),
                    $band('spf'),
                    $band('dkim'),
                    $band('dmarc'),
                    $band('autodiscover'),
                    $band('mta_sts'),
                    $band('tls_rpt'),
                    $band('bimi'),
                    optional($domain->last_checked_at)?->toIso8601String() ?? '',
                ]);
            }
            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function destroyDomain(Client $client, ClientDomain $domain): RedirectResponse
    {
        $this->authorize('update', $client);
        abort_unless($domain->client_id === $client->id, 404);

        $this->domains->removeDomain($domain);
        $this->activityLog->log('client.domain_removed', $client, clientId: $client->id);

        return redirect()->route('admin.clients.edit', $client)
            ->with('success', 'Domain removed.');
    }

    /**
     * @return array<string, mixed>|null
     */
    private function scimProvisioningHealth(Client $client): ?array
    {
        if (! $client->exists) {
            return null;
        }

        return $this->onboarding->scimExportHealth($client, live: false);
    }

    private function queueOnboardingChecksWarmIfCold(Client $client): void
    {
        if (! $client->exists) {
            return;
        }

        $scimCold = filled($client->entra_tenant_id)
            && filled($client->entra_superops_app_id)
            && ! Cache::has(ClientOnboardingService::scimHealthCacheKey($client->id));
        $requesterCountCold = filled($client->superops_account_id)
            && ! Cache::has('superops.requester_count.'.$client->id);
        $dkimCold = filled($client->entra_tenant_id)
            && ! Cache::has($this->dkimSigning->cacheKey($client->id));

        if ($scimCold || $requesterCountCold || $dkimCold) {
            WarmClientOnboardingChecksJob::dispatch($client->id);
        }
    }

    public function store(StoreClientRequest $request): RedirectResponse
    {
        $this->authorize('create', Client::class);

        $client = Client::create([
            'name' => $request->name,
            'slug' => $this->uniqueSlug($request->name),
            'primary_domain' => $request->primary_domain,
            'superops_account_id' => $request->superops_account_id,
            'superops_sso_enabled' => $request->boolean('superops_sso_enabled'),
            'pax8_company_id' => $request->pax8_company_id,
            'pax8_sso_enabled' => filled($request->pax8_company_id),
            'dropsuite_organization_id' => $request->dropsuite_organization_id,
            'huntress_organization_id' => $request->huntress_organization_id,
            'usecure_company_id' => $request->usecure_company_id,
            'entra_tenant_id' => null,
            'entra_license_tier' => ClientOnboardingService::ENTRA_LICENSE_FREE,
            'entra_group_id' => null,
            'entra_superops_app_id' => null,
            'entra_superops_sso_app_id' => null,
            'entra_sync_enabled' => false,
            'is_active' => $request->boolean('is_active', true),
        ]);

        $primaryRow = $this->domains->setPrimaryDomain($client, (string) $request->primary_domain, queueCheck: false);
        $this->domains->checkNow($primaryRow, sync: true, userId: $request->user()?->id, source: 'heal');

        $productToggles = $request->input('products', []);
        if (is_array($productToggles) && $productToggles !== []) {
            $this->products->applyEntitlements($client, $productToggles);
        }
        $this->products->syncEntitlementsFromMappings($client->fresh());
        $client->refresh();
        $this->onboarding->syncAutoCheckpointsFromClient($client);

        $this->activityLog->log('client.created', $client, clientId: $client->id);

        app(\App\Services\Portal\DashboardFeedRegistry::class)->queueMissingSnapshots($client);

        return redirect()->route('admin.clients.edit', $client)
            ->with('success', 'Client created. Start with step 01 on the right.');
    }

    public function edit(Client $client): View
    {
        $this->authorize('update', $client);

        // Older rows may have clients.primary_domain without a client_domains DNS row.
        if (filled($client->primary_domain) && ! $client->domains()->exists()) {
            $row = $this->domains->setPrimaryDomain($client, (string) $client->primary_domain, queueCheck: false);
            $this->domains->checkNow($row, sync: true, userId: request()->user()?->id, source: 'heal');
            $client->refresh();
        }

        $this->onboarding->syncAutoCheckpointsFromClient($client);
        $client->refresh();

        return view('admin.clients.edit', $this->onboardingViewData($client));
    }

    public function update(UpdateClientRequest $request, Client $client): RedirectResponse
    {
        $this->authorize('update', $client);

        $client->update([
            'name' => $request->name,
            'slug' => $this->uniqueSlug($request->name, $client->id),
            'primary_domain' => $request->primary_domain,
            'superops_account_id' => $request->superops_account_id,
            'superops_sso_enabled' => $request->boolean('superops_sso_enabled'),
            'pax8_company_id' => $request->pax8_company_id,
            'pax8_sso_enabled' => filled($request->pax8_company_id),
            'dropsuite_organization_id' => $request->dropsuite_organization_id,
            'huntress_organization_id' => $request->huntress_organization_id,
            'usecure_company_id' => $request->usecure_company_id,
            'entra_tenant_id' => $request->entra_tenant_id,
            'entra_license_tier' => $request->input('entra_license_tier', ClientOnboardingService::ENTRA_LICENSE_FREE),
            'entra_group_id' => $request->entra_group_id,
            'entra_superops_app_id' => $request->entra_superops_app_id,
            'entra_superops_sso_app_id' => $request->entra_superops_sso_app_id,
            'entra_sync_enabled' => $request->boolean('entra_sync_enabled'),
            'is_active' => $request->boolean('is_active'),
        ]);

        // Sync DNS for the primary so Edit shows real bands immediately (not "red / not checked").
        $primaryRow = $this->domains->setPrimaryDomain($client->fresh(), (string) $request->primary_domain, queueCheck: false);
        $this->domains->checkNow($primaryRow, sync: true, userId: $request->user()?->id, source: 'sync');
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

        // First snapshot for every sold feed. The hourly idle prewarm is only for later refreshes.
        app(\App\Services\Portal\DashboardFeedRegistry::class)->queueMissingSnapshots($client);

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

        // CustomerEntraBootstrapService::bootstrap() makes a long chain of sequential
        // Graph calls (consent-propagation waits, service-principal polling) that can
        // exceed nginx's 60s gateway - same reason applyScim()/retryScimExport() are queued.
        // claim() refuses while a run is pending/running (see ClaimsInFlightSlot, L9).
        if (! BootstrapClientEntraJob::claim($client->id)) {
            return redirect()->route('admin.clients.edit', $client)
                ->with('error', 'Graph setup is already running for this client, so it was not started again. '
                    .'Wait for it to finish (usually under 2 minutes), then refresh this page.');
        }

        BootstrapClientEntraJob::dispatch($client->id);

        $this->activityLog->log('client.entra_bootstrap_queued', $client, clientId: $client->id);

        return redirect()->route('admin.clients.edit', $client)
            ->with(
                'success',
                'Entra bootstrap is running in the background (usually under 2 minutes). '
                .'You can leave this page - refresh to check the banner under Bootstrap Entra. '
                .'This avoids the previous timeout when Graph is slow.'
            );
    }

    public function applyScim(ApplySuperOpsScimRequest $request, Client $client): RedirectResponse
    {
        $this->authorize('update', $client);

        if (! filled($client->entra_tenant_id) || ! filled($client->entra_superops_app_id)) {
            return redirect()->route('admin.clients.edit', $client)
                ->with('error', 'Connect Microsoft first so Tenant ID and SuperOps SCIM Application (client) ID are saved.');
        }

        // Graph waits (schema / already-exists) can exceed nginx's 60s gateway - never do that inline.
        // A second submit while one is pending/running used to be dropped silently by
        // ShouldBeUnique (new URL/token lost, page still said "running"). Refuse it
        // visibly instead; the secret token is never flashed back into the session.
        if (! ApplySuperOpsScimJob::claim($client->id)) {
            return redirect()->route('admin.clients.edit', $client)
                ->withInput($request->except('scim_secret_token'))
                ->with('error', 'Apply SCIM is already running for this client, so these new values were NOT applied. '
                    .'Wait for it to finish (usually under 2 minutes), refresh this page to see the result, then submit again if the values still need changing.');
        }

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

        // Same rule as the view's disabled Retry button: not while Apply SCIM or an
        // earlier retry is pending/running (both drive the same Entra SCIM job).
        if (ApplySuperOpsScimJob::isInFlight($client->id) || ! RepairSuperOpsScimExportJob::claim($client->id)) {
            return redirect()->route('admin.clients.edit', $client)
                ->with('error', 'A SCIM job (Apply SCIM or Retry SCIM export) is already running for this client, so Retry was not started. '
                    .'Wait for it to finish, then refresh this page.');
        }

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

        // applyClientSsoSamlConfiguration() waits on application/service-principal
        // readiness the same way applySuperOpsScimCredentials() does - same nginx
        // 504 risk applyScim()/retryScimExport() are already queued for. The resulting
        // Login URL/certificate are written to the client_sso_idp.{id} cache key by the
        // job itself, and the Blade view polls that key + client_sso_apply.in_flight the
        // same way it already polls scim_apply.in_flight/scim_apply.last_result.
        // Refuse (visibly, keeping the typed values) rather than let ShouldBeUnique
        // drop a second submit's Entity ID / Reply URL silently (L9).
        if (! ApplyClientSsoSamlJob::claim($client->id)) {
            return redirect()->route('admin.clients.edit', $client)
                ->withInput()
                ->with('error', 'Wire SuperOps into Microsoft Entra is already running for this client, so these new values were NOT applied. '
                    .'Wait for it to finish (usually under 2 minutes), refresh this page to see the result, then submit again if the values still need changing.');
        }

        ApplyClientSsoSamlJob::dispatch(
            $client->id,
            $request->validated('entity_id'),
            $request->validated('consumer_service_url'),
        );

        $this->activityLog->log(
            'client.client_sso_apply_queued',
            $client,
            properties: [
                'entity_host' => parse_url($request->validated('entity_id'), PHP_URL_HOST),
            ],
            clientId: $client->id,
        );

        return redirect()->route('admin.clients.edit', $client)
            ->with(
                'success',
                'Wire SuperOps into Microsoft Entra is running in the background (usually under 2 minutes). '
                .'You can leave this page - refresh to check the banner below for the Login URL and certificate. '
                .'This avoids the previous timeout when Graph is slow.'
            );
    }

    /**
     * clients.slug is UNIQUE, but different names can slug identically ("Acme Ltd" /
     * "Acme Ltd.") and symbol-only names slug to "" - a bare Str::slug() then 500s the
     * save with a unique-constraint violation.
     */
    private function uniqueSlug(string $name, ?int $ignoreClientId = null): string
    {
        $base = Str::slug($name) ?: 'client';
        $slug = $base;

        for ($suffix = 2; Client::query()
            ->where('slug', $slug)
            ->when($ignoreClientId !== null, fn ($q) => $q->whereKeyNot($ignoreClientId))
            ->exists(); $suffix++) {
            $slug = $base.'-'.$suffix;
        }

        return $slug;
    }

    public function destroy(Client $client): RedirectResponse
    {
        $this->authorize('delete', $client);

        $this->activityLog->log('client.deleted', $client, clientId: $client->id);

        // users.client_id is nullOnDelete: without this the client's users would
        // survive as active, client-less accounts that appear in no admin listing.
        \Illuminate\Support\Facades\DB::transaction(function () use ($client): void {
            $client->users()->update(['is_active' => false]);
            $client->delete();
        });

        return redirect()->route('admin.clients.index')
            ->with('success', 'Client deleted successfully.');
    }
}
