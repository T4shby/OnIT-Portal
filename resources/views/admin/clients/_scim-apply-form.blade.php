@php
    $inStep = (bool) ($inStep ?? false);
    $idPrefix = $inStep ? 'step_' : 'side_';
    $ready = isset($client) && $client->exists
        && filled($client->entra_tenant_id)
        && filled($client->entra_superops_app_id);
    $scimInFlight = $ready && \Illuminate\Support\Facades\Cache::has(
        \App\Jobs\ApplySuperOpsScimJob::IN_FLIGHT_KEY_PREFIX.$client->id
    );
    $scimLastResult = $ready
        ? \Illuminate\Support\Facades\Cache::get(\App\Jobs\ApplySuperOpsScimJob::LAST_RESULT_KEY_PREFIX.$client->id)
        : null;
@endphp

@if($ready)
    <div @class(['mt-6 border-t border-white/10 pt-6' => ! $inStep])>
        <div class="mb-4 border border-emerald-500/30 bg-emerald-500/5 px-4 py-3 text-sm leading-relaxed text-white/80">
            <p class="portal-label mb-2 text-emerald-300/90">Already automatic</p>
            <p>
                Entra app <strong class="text-white/90">SuperOps - {{ $client->name }}</strong> (Connect),
                Start provisioning, and P1 group assign when bootstrap succeeds.
                Apply only turns step 07 <strong class="text-white/90">Done</strong> when
                <strong class="text-white/90">name.familyName ← extensionAttribute1</strong> is set
                <strong class="text-white/90">and</strong> portal Sync is queued (group ID must be saved).
            </p>
        </div>
        <p class="portal-label mb-2">@if($inStep) Still needs you @else Push SuperOps SCIM to Entra @endif</p>
        <p class="mb-4 text-sm leading-relaxed text-white/75">
            Paste SuperOps <strong class="text-white/90">Tenant URL</strong> and <strong class="text-white/90">Secret Token</strong>
            (from step 05 Generate Tokens). Secret is <strong class="text-white/90">not stored</strong> after the job finishes.
            Apply runs in a <strong class="text-white/90">background worker</strong> (avoids 504 Gateway Time-out while Graph waits).
            Credentials-only is <strong class="text-white/90">not enough</strong> — mapping + Sync queue must both succeed.
        </p>

        @if($scimInFlight)
            <div class="mb-4 border border-amber-500/40 bg-amber-500/10 px-4 py-3 text-sm leading-relaxed text-amber-100">
                Apply SCIM is running in the background. Refresh this page in about a minute — step 07 turns Done when mappings + Sync queue succeed.
            </div>
        @elseif(is_array($scimLastResult))
            <div @class([
                'mb-4 border px-4 py-3 text-sm leading-relaxed',
                'border-emerald-500/40 bg-emerald-500/10 text-emerald-100' => ($scimLastResult['success'] ?? false) || ($scimLastResult['step_complete'] ?? false),
                'border-amber-500/40 bg-amber-500/10 text-amber-100' => ! (($scimLastResult['success'] ?? false) || ($scimLastResult['step_complete'] ?? false)),
            ])>
                <p class="portal-label mb-1">Last Apply SCIM result</p>
                <p>{{ $scimLastResult['message'] ?? 'Finished' }}</p>
                @if(! empty($scimLastResult['blockers']) && is_array($scimLastResult['blockers']))
                    <p class="mt-2">{{ implode(' ', $scimLastResult['blockers']) }}</p>
                @endif
            </div>
        @endif

        {{-- Do NOT disable the inputs on submit: disabled controls are omitted from the POST. --}}
        <form
            method="POST"
            action="{{ route('admin.clients.apply-scim', $client) }}"
            class="space-y-4"
            x-data="{ submitting: false }"
            @submit="submitting = true"
        >
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
                    :readonly="submitting"
                    :class="{ 'opacity-70 pointer-events-none': submitting }"
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
                    autocomplete="off"
                    data-1p-ignore
                    data-lpignore="true"
                    data-form-type="other"
                    placeholder="scim-…"
                    class="admin-input"
                    :readonly="submitting"
                    :class="{ 'opacity-70 pointer-events-none': submitting }"
                >
                @error('scim_secret_token')
                    <p class="mt-1 text-sm text-red-400">{{ $message }}</p>
                @enderror
            </div>
            <button type="submit" class="cta-btn text-sm px-6 py-3" :disabled="submitting || {{ $scimInFlight ? 'true' : 'false' }}">
                <span x-show="!submitting">@if($scimInFlight) Apply already running… @else Apply SCIM credentials + start @endif</span>
                <span x-cloak x-show="submitting">Queued — redirecting…</span>
            </button>
            <p class="portal-body-muted text-xs leading-relaxed" x-show="!submitting">
                Queues a high-priority worker job: Graph tokens + name.familyName ← extensionAttribute1 + portal Sync.
                Step stays Pending if either fails. Re-Apply if the last result shows an error (job reuses Entra SCIM job).
            </p>
        </form>
    </div>
@elseif(isset($client) && $client->exists && filled($client->entra_tenant_id) && blank($client->entra_superops_app_id))
    <p class="portal-body-muted @if(! $inStep) mt-6 @endif text-xs">
        Connect Microsoft / bootstrap first so SuperOps SCIM Application (client) ID is saved.
    </p>
@endif
