@php
    $ssoReady = filled($client->entra_tenant_id ?? null) && filled($client->entra_superops_sso_app_id ?? null);
    $ssoCache = $ssoReady ? cache('client_sso_idp.'.$client->id) : null;
    $ssoLoginUrl = session('client_sso_login_url') ?? ($ssoCache['loginUrl'] ?? null);
    $ssoCertificate = session('client_sso_certificate') ?? ($ssoCache['certificateBase64'] ?? null);
@endphp

@if(isset($client) && $client->exists && $ssoReady)
    <div class="mt-6 border-t border-white/10 pt-6">
        <p class="portal-label mb-2">Configure Client SSO SAML in Entra</p>
        <p class="mb-4 text-sm leading-relaxed text-white/75">
            From SuperOps Client SSO, paste <strong class="text-white/90">Entity ID</strong> and
            <strong class="text-white/90">Consumer Service URL</strong>. Portal configures
            <strong class="text-white/90">SuperOps Requester SSO - {{ $client->name }}</strong> automatically,
            then shows <strong class="text-white/90">Login URL + certificate</strong> to paste back into SuperOps.
            You do not open Azure for step 08 SAML unless this fails.
        </p>

        <form method="POST" action="{{ route('admin.clients.apply-client-sso', $client) }}" class="space-y-4">
            @csrf
            <div>
                <label for="entity_id" class="portal-label mb-2 block">SuperOps Entity ID</label>
                <input
                    type="url"
                    name="entity_id"
                    id="entity_id"
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
                <label for="consumer_service_url" class="portal-label mb-2 block">SuperOps Consumer Service URL</label>
                <input
                    type="url"
                    name="consumer_service_url"
                    id="consumer_service_url"
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
                <p class="portal-label">Paste these into SuperOps Client SSO (step 3)</p>
                <div>
                    <label class="portal-label mb-2 block">IDP Login URL</label>
                    <textarea readonly rows="2" class="admin-input text-xs" onclick="this.select()">{{ $ssoLoginUrl }}</textarea>
                </div>
                <div>
                    <label class="portal-label mb-2 block">Certificate (Base64 body — no BEGIN/END lines)</label>
                    <textarea readonly rows="6" class="admin-input font-mono text-xs" onclick="this.select()">{{ $ssoCertificate }}</textarea>
                </div>
                <p class="portal-body-muted text-xs leading-relaxed">
                    SuperOps Client SSO → paste Login URL + certificate → Save / enable. Logout URL can stay empty.
                    Then assign users (Free: Sync now; P1: group on SSO app).
                </p>
            </div>
        @endif
    </div>
@elseif(isset($client) && $client->exists && filled($client->entra_tenant_id) && blank($client->entra_superops_sso_app_id))
    <p class="portal-body-muted mt-6 text-xs">
        Re-run Entra bootstrap so Client SSO Application (client) ID is saved — then SAML configure appears here.
    </p>
@endif
