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
    $scimHealth = ($scimProvisioningHealth ?? null) ?? ($ready
        ? \Illuminate\Support\Facades\Cache::get('scim.health.'.$client->id)
        : null);
    $scimRepairInFlight = $ready && \Illuminate\Support\Facades\Cache::has(
        \App\Jobs\RepairSuperOpsScimExportJob::IN_FLIGHT_KEY_PREFIX.$client->id
    );
    $scimRepairLastResult = $ready
        ? \Illuminate\Support\Facades\Cache::get(\App\Jobs\RepairSuperOpsScimExportJob::LAST_RESULT_KEY_PREFIX.$client->id)
        : null;
    $canRetryScimExport = $ready
        && is_array($scimHealth)
        && ! ($scimHealth['needsApplyScim'] ?? false)
        && (($scimHealth['needsRepair'] ?? false) || ! ($scimHealth['ok'] ?? false));
@endphp

@if($ready)
    <div @class(['mt-6 border-t border-white/10 pt-6' => ! $inStep])>
        @if(is_array($scimHealth) && (($scimHealth['needsRepair'] ?? false) || ($scimHealth['needsApplyScim'] ?? false)))
            <div class="mb-4 border border-red-500/40 bg-red-500/10 px-4 py-3 text-sm leading-relaxed text-red-100">
                <p class="portal-label mb-1 text-red-200/90">SuperOps SCIM export stopped (Sync 1)</p>
                @if($scimHealth['error'] ?? null)
                    <p>{{ $scimHealth['error'] }}</p>
                @elseif($scimHealth['needsApplyScim'] ?? false)
                    <p>
                        Entra has no SuperOps SCIM Tenant URL stored. Portal Sync 2 can still update M365 and the portal,
                        but new requesters will not reach SuperOps until you <strong class="text-white/90">Apply SCIM</strong>
                        below with Tenant URL + Secret Token from SuperOps step 05.
                    </p>
                @else
                    <p>
                        Apply SCIM saved credentials but Entra export did not fully start (common Graph lag on first run).
                        Click <strong class="text-white/90">Retry SCIM export</strong> below — no secret re-paste, no Azure UI.
                    </p>
                @endif
                @if($canRetryScimExport)
                    <form method="POST" action="{{ route('admin.clients.retry-scim-export', $client) }}" class="mt-3">
                        @csrf
                        <button type="submit" class="cta-btn text-sm px-5 py-2.5" @disabled($scimRepairInFlight || $scimInFlight)>
                            @if($scimRepairInFlight) Retry running… @else Retry SCIM export @endif
                        </button>
                    </form>
                @endif
                @if(! empty($scimHealth['warnings']) && is_array($scimHealth['warnings']))
                    <ul class="mt-2 list-disc space-y-1 pl-5 text-red-100/90">
                        @foreach($scimHealth['warnings'] as $warning)
                            <li>{{ $warning }}</li>
                        @endforeach
                    </ul>
                @endif
            </div>
        @endif
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

        @if($scimRepairInFlight)
            <div class="mb-4 border border-amber-500/40 bg-amber-500/10 px-4 py-3 text-sm leading-relaxed text-amber-100">
                Retry SCIM export is running in the background. Refresh in about a minute — check Integration Health → SuperOps SCIM.
            </div>
        @elseif(is_array($scimRepairLastResult))
            <div @class([
                'mb-4 border px-4 py-3 text-sm leading-relaxed',
                'border-emerald-500/40 bg-emerald-500/10 text-emerald-100' => ($scimRepairLastResult['success'] ?? false),
                'border-amber-500/40 bg-amber-500/10 text-amber-100' => ! ($scimRepairLastResult['success'] ?? false),
            ])>
                <p class="portal-label mb-1">Last Retry SCIM export result</p>
                <p>{{ $scimRepairLastResult['message'] ?? 'Finished' }}</p>
                @if(! empty($scimRepairLastResult['blockers']) && is_array($scimRepairLastResult['blockers']))
                    <p class="mt-2">{{ implode(' ', $scimRepairLastResult['blockers']) }}</p>
                @endif
            </div>
        @endif

        @if($canRetryScimExport && ! ($scimHealth['needsApplyScim'] ?? false))
            <form method="POST" action="{{ route('admin.clients.retry-scim-export', $client) }}" class="mb-4">
                @csrf
                <button type="submit" class="cta-btn-ghost text-sm px-6 py-3" @disabled($scimRepairInFlight || $scimInFlight)>
                    @if($scimRepairInFlight) Retry SCIM export running… @else Retry SCIM export (credentials already in Entra) @endif
                </button>
                <p class="portal-body-muted mt-2 text-xs leading-relaxed">
                    Recreates the Entra <strong class="text-white/80">scim.*</strong> job, sets name mappings, starts export, pushes missing SuperOps requesters, queues Sync.
                    Use this when Apply SCIM stopped halfway — not when tokens were never pasted.
                </p>
            </form>
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
