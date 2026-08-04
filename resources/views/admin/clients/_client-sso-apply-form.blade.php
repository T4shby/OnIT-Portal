@php
    $inStep = (bool) ($inStep ?? false);
    $idPrefix = $inStep ? 'step_' : 'side_';
    $ssoReady = isset($client) && $client->exists
        && filled($client->entra_tenant_id ?? null)
        && filled($client->entra_superops_sso_app_id ?? null);
    $ssoCache = $ssoReady ? cache('client_sso_idp.'.$client->id) : null;
    $ssoLoginUrl = session('client_sso_login_url') ?? ($ssoCache['loginUrl'] ?? null);
    $ssoCertificate = session('client_sso_certificate') ?? ($ssoCache['certificateBase64'] ?? null);
@endphp

@if($ssoReady)
    <div @class(['mt-6 border-t border-white/10 pt-6' => ! $inStep])>
        <p class="portal-label mb-2">@if($inStep) Do this now @else Configure Client SSO SAML in Entra @endif</p>
        <p class="mb-4 text-sm leading-relaxed text-white/75">
            From SuperOps Client SSO, paste <strong class="text-white/90">Entity ID</strong> and
            <strong class="text-white/90">Consumer Service URL</strong> (full HTTPS URLs).
            Portal configures <strong class="text-white/90">SuperOps Requester SSO - {{ $client->name }}</strong>,
            then shows <strong class="text-white/90">Login URL + certificate</strong> for SuperOps.
        </p>

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
                <label for="{{ $idPrefix }}consumer_service_url" class="portal-label mb-2 block">SuperOps Consumer Service URL</label>
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
                Configure SAML in Entra
            </button>
        </form>

        @if(filled($ssoLoginUrl) && filled($ssoCertificate))
            <div class="mt-6 space-y-4 border border-onit/35 bg-onit/10 p-4">
                <p class="portal-label">Paste into SuperOps Client SSO</p>
                <div>
                    <label class="portal-label mb-2 block">IDP Login URL</label>
                    <textarea readonly rows="2" class="admin-input text-xs" onclick="this.select()">{{ $ssoLoginUrl }}</textarea>
                </div>
                <div>
                    <label class="portal-label mb-2 block">Certificate (Base64 body — no BEGIN/END)</label>
                    <textarea readonly rows="6" class="admin-input font-mono text-xs" onclick="this.select()">{{ $ssoCertificate }}</textarea>
                </div>
                <p class="portal-body-muted text-xs leading-relaxed">
                    SuperOps → paste Login URL + certificate → Save / enable. Then portal Sync now (Free) or group assign (P1).
                </p>
            </div>
        @endif
    </div>
@elseif(isset($client) && $client->exists && filled($client->entra_tenant_id) && blank($client->entra_superops_sso_app_id))
    <p class="portal-body-muted @if(! $inStep) mt-6 @endif text-xs">
        Re-run Entra bootstrap so Client SSO Application (client) ID is saved — then this form appears.
    </p>
@endif
