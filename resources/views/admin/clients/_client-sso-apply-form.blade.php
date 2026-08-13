@php
    $inStep = (bool) ($inStep ?? false);
    $idPrefix = $inStep ? 'step_' : 'side_';
    $ssoAppId = filled($client->entra_superops_sso_app_id ?? null);
    $ssoReady = isset($client) && $client->exists
        && filled($client->entra_tenant_id ?? null)
        && $ssoAppId;
    $ssoCache = $ssoReady ? cache('client_sso_idp.'.$client->id) : null;
    $ssoLoginUrl = session('client_sso_login_url') ?? ($ssoCache['loginUrl'] ?? null);
    $ssoCertificate = session('client_sso_certificate') ?? ($ssoCache['certificateBase64'] ?? null);
    $ssoWired = filled($ssoLoginUrl) && filled($ssoCertificate);
    $ssoStepDone = (bool) (($client->onboarding_checklist ?? [])['superops_client_sso_configured'] ?? false);
    $ssoAppLabel = 'SuperOps Requester SSO - '.($client->name ?? 'Customer');
    $wireFailed = (isset($errors) && ($errors->has('entity_id') || $errors->has('consumer_service_url')))
        || session()->has('error');
@endphp

@if($ssoReady)
    <div @class(['mt-6 border-t border-white/10 pt-6' => ! $inStep])>
        {{-- Automated status --}}
        <div class="mb-5 border border-emerald-500/30 bg-emerald-500/5 px-4 py-3 text-sm leading-relaxed text-white/80">
            <p class="portal-label mb-2 text-emerald-300/90">Already automatic</p>
            <ul class="list-disc space-y-1 pl-5">
                <li>Entra app <strong class="text-white/90">{{ $ssoAppLabel }}</strong> created (Connect Microsoft)</li>
                <li>App role User + portal group assignment where licence allows</li>
                @if($ssoWired)
                    <li>SAML wired for this customer — Login URL + certificate ready below</li>
                @endif
            </ul>
        </div>

        @if($ssoStepDone || $ssoWired)
            <p class="portal-label mb-2 text-emerald-300/90">Step status</p>
            <p class="mb-4 text-sm leading-relaxed text-white/80">
                @if($ssoStepDone)
                    Marked complete on the checklist.
                @else
                    Entra side is ready. Finish SuperOps Step 3 (paste Login URL + cert → Save) if you have not already.
                @endif
                You only need the form below if you are wiring a <strong class="text-white/90">new</strong> SuperOps Client SSO config or re-running after a failure.
                @if($ssoStepDone && $ssoWired)
                    Entity ID / Consumer URL inputs stay empty on purpose — those values live in Entra and SuperOps now.
                @endif
            </p>
        @else
            <p class="portal-label mb-2">Still needs you (about 2 minutes)</p>
            <p class="mb-4 text-sm leading-relaxed text-white/75">
                SuperOps does not hand Entity ID / ACS to us over API — you copy two URLs from SuperOps,
                the portal pushes them into Entra, then you paste Login URL + certificate back into SuperOps Step 3.
            </p>
        @endif

        <details class="border border-white/10 bg-white/[0.02] px-4 py-3" @if(! $ssoStepDone || $wireFailed || ! $ssoWired) open @endif>
            <summary class="cursor-pointer select-none text-sm font-condensed uppercase tracking-wide text-onit">
                @if($ssoWired) Re-wire SuperOps ↔ Entra (optional) @else Finish SuperOps Client SSO @endif
            </summary>

            <div class="mt-4 space-y-4">
                <ol class="list-decimal space-y-2 pl-5 text-sm leading-relaxed text-white/80">
                    <li>
                        SuperOps → <strong class="text-white/90">Requester Login → SSO Protected → Client SSO</strong>
                        → configuration for this company → copy
                        <strong class="text-white/90">Entity ID</strong> and
                        <strong class="text-white/90">Consumer service URL</strong>.
                    </li>
                    <li>Paste them here → <strong class="text-white/90">Wire SuperOps into Microsoft Entra</strong>.</li>
                    <li>Copy the Login URL + certificate this page returns into SuperOps Step 3 → Save.</li>
                </ol>

                <form method="POST" action="{{ route('admin.clients.apply-client-sso', $client) }}" class="space-y-4">
                    @csrf
                    <div>
                        <label for="{{ $idPrefix }}entity_id" class="portal-label mb-2 block">SuperOps Entity ID</label>
                        <input
                            type="url"
                            name="entity_id"
                            id="{{ $idPrefix }}entity_id"
                            value="{{ old('entity_id', $ssoCache['entityId'] ?? '') }}"
                            required
                            autocomplete="off"
                            placeholder="https://clientuser.superops.ai/saml/…/meta"
                            class="admin-input"
                        >
                        @error('entity_id')
                            <p class="mt-1 text-sm text-red-400">{{ $message }}</p>
                        @enderror
                    </div>
                    <div>
                        <label for="{{ $idPrefix }}consumer_service_url" class="portal-label mb-2 block">SuperOps Consumer service URL</label>
                        <input
                            type="url"
                            name="consumer_service_url"
                            id="{{ $idPrefix }}consumer_service_url"
                            value="{{ old('consumer_service_url', $ssoCache['consumerServiceUrl'] ?? '') }}"
                            required
                            autocomplete="off"
                            placeholder="https://portal.onit.ltd/accounts-web/accounts/saml/response/…"
                            class="admin-input"
                        >
                        @error('consumer_service_url')
                            <p class="mt-1 text-sm text-red-400">{{ $message }}</p>
                        @enderror
                    </div>
                    <button type="submit" class="cta-btn text-sm px-6 py-3">
                        Wire SuperOps into Microsoft Entra
                    </button>
                </form>
            </div>
        </details>

        @if($ssoWired)
            <div class="mt-6 space-y-4 border border-onit/35 bg-onit/10 p-4">
                <p class="portal-label">Paste into SuperOps Step 3 (if not already saved there)</p>
                <div>
                    <label class="portal-label mb-2 block">IDP Login URL</label>
                    <textarea readonly rows="2" class="admin-input text-xs" onclick="this.select()">{{ $ssoLoginUrl }}</textarea>
                </div>
                <div>
                    <label class="portal-label mb-2 block">Certificate (Base64, no BEGIN/END)</label>
                    <textarea readonly rows="6" class="admin-input font-mono text-xs" onclick="this.select()">{{ $ssoCertificate }}</textarea>
                </div>
            </div>
        @endif

        @if($wireFailed)
            <p class="mt-4 border border-amber-500/40 bg-amber-500/10 px-4 py-3 text-sm text-amber-100">
                Wire did not finish cleanly. Open <strong class="text-white">Only if something failed</strong> in the guide below for the Azure fallback, or retry after checking the SuperOps URLs.
            </p>
        @endif
    </div>
@elseif(isset($client) && $client->exists && filled($client->entra_tenant_id) && blank($client->entra_superops_sso_app_id))
    <div class="border border-amber-500/40 bg-amber-500/10 px-4 py-3 text-sm leading-relaxed text-amber-100">
        <p class="portal-label mb-2 text-amber-200">Automation incomplete</p>
        <p>
            Client SSO Entra app ID is missing. Use
            <strong class="text-white">Connect Microsoft tenant</strong> /
            <strong class="text-white">Re-run Entra bootstrap</strong>,
            then return here. The detailed recovery path is in the guide under “If something failed”.
        </p>
    </div>
@endif
