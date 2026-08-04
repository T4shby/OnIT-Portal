@if(isset($client) && $client->exists && filled($client->entra_tenant_id) && filled($client->entra_superops_app_id))
    <div class="mt-6 border-t border-white/10 pt-6">
        <p class="portal-label mb-2">Push SuperOps SCIM to Entra</p>
        <p class="mb-4 text-sm leading-relaxed text-white/75">
            Paste the SuperOps <strong class="text-white/90">Tenant URL</strong> and <strong class="text-white/90">Secret Token</strong> once.
            The portal writes them into <strong class="text-white/90">SuperOps - {{ $client->name }}</strong> provisioning and starts the job.
            The secret is <strong class="text-white/90">not stored</strong> in this portal.
        </p>

        <form method="POST" action="{{ route('admin.clients.apply-scim', $client) }}" class="space-y-4">
            @csrf
            <div>
                <label for="scim_tenant_url" class="portal-label mb-2 block">SuperOps SCIM Tenant URL</label>
                <input
                    type="url"
                    name="scim_tenant_url"
                    id="scim_tenant_url"
                    value="{{ old('scim_tenant_url') }}"
                    required
                    autocomplete="off"
                    placeholder="https://usserv.superops.ai/accounts-web/scim/…"
                    class="admin-input"
                >
                @error('scim_tenant_url')
                    <p class="mt-1 text-sm text-red-400">{{ $message }}</p>
                @enderror
            </div>
            <div>
                <label for="scim_secret_token" class="portal-label mb-2 block">SuperOps SCIM Secret Token</label>
                <input
                    type="password"
                    name="scim_secret_token"
                    id="scim_secret_token"
                    value=""
                    required
                    autocomplete="new-password"
                    placeholder="scim-…"
                    class="admin-input"
                >
                @error('scim_secret_token')
                    <p class="mt-1 text-sm text-red-400">{{ $message }}</p>
                @enderror
            </div>
            <button type="submit" class="cta-btn text-sm px-6 py-3">
                Apply SCIM credentials + start
            </button>
            <p class="portal-body-muted text-xs leading-relaxed">
                Still verify attribute mapping once (name.familyName ← extensionAttribute1 Direct) if SuperOps names look wrong after first sync.
            </p>
        </form>
    </div>
@elseif(isset($client) && $client->exists && filled($client->entra_tenant_id) && blank($client->entra_superops_app_id))
    <p class="portal-body-muted mt-6 text-xs">
        Connect Microsoft tenant / Entra bootstrap first so the SuperOps SCIM app ID is saved — then this form appears.
    </p>
@endif
