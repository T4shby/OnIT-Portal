@php
    $inStep = (bool) ($inStep ?? false);
    $idPrefix = $inStep ? 'step_' : 'side_';
    $ready = isset($client) && $client->exists
        && filled($client->entra_tenant_id)
        && filled($client->entra_superops_app_id);
@endphp

@if($ready)
    <div @class(['mt-6 border-t border-white/10 pt-6' => ! $inStep])>
        <p class="portal-label mb-2">@if($inStep) Do this now @else Push SuperOps SCIM to Entra @endif</p>
        <p class="mb-4 text-sm leading-relaxed text-white/75">
            Paste SuperOps <strong class="text-white/90">Tenant URL</strong> and <strong class="text-white/90">Secret Token</strong>.
            Portal writes them into <strong class="text-white/90">SuperOps - {{ $client->name }}</strong> and starts provisioning.
            Secret is <strong class="text-white/90">not stored</strong>.
        </p>

        <form method="POST" action="{{ route('admin.clients.apply-scim', $client) }}" class="space-y-4">
            @csrf
            <div>
                <label for="{{ $idPrefix }}scim_tenant_url" class="portal-label mb-2 block">SuperOps SCIM Tenant URL</label>
                <input
                    type="url"
                    name="scim_tenant_url"
                    id="{{ $idPrefix }}scim_tenant_url"
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
                <label for="{{ $idPrefix }}scim_secret_token" class="portal-label mb-2 block">SuperOps SCIM Secret Token</label>
                <input
                    type="password"
                    name="scim_secret_token"
                    id="{{ $idPrefix }}scim_secret_token"
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
                Optional later: name.familyName ← extensionAttribute1 Direct if SuperOps last names look wrong.
            </p>
        </form>
    </div>
@elseif(isset($client) && $client->exists && filled($client->entra_tenant_id) && blank($client->entra_superops_app_id))
    <p class="portal-body-muted @if(! $inStep) mt-6 @endif text-xs">
        Connect Microsoft / bootstrap first so SuperOps SCIM Application (client) ID is saved.
    </p>
@endif
