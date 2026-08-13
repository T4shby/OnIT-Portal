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
    $scimExportBroken = is_array($scimHealth) && ! ($scimHealth['ok'] ?? false);
    $scimNeedsApply = is_array($scimHealth) && ($scimHealth['needsApplyScim'] ?? false);
    $scimCredentialsInEntra = is_array($scimHealth)
        && ! $scimNeedsApply
        && filled($scimHealth['scimBaseAddress'] ?? null);
    $scimBaseAddressHost = $scimCredentialsInEntra
        ? parse_url((string) $scimHealth['scimBaseAddress'], PHP_URL_HOST)
        : null;
    $canRetryScimExport = $ready
        && is_array($scimHealth)
        && ! $scimNeedsApply
        && (($scimHealth['needsRepair'] ?? false) || ! ($scimHealth['ok'] ?? false));
@endphp

@if($ready)
    <div @class(['mt-6 border-t border-white/10 pt-6' => ! $inStep])>
        @if($scimExportBroken)
            <div class="mb-4 rounded border border-red-500/45 bg-red-950/40 px-4 py-4 text-sm leading-relaxed text-red-50">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <div class="min-w-0 flex-1">
                        <p class="portal-label mb-1 text-red-200">
                            @if($scimNeedsApply)
                                SuperOps SCIM credentials missing in Entra
                            @else
                                SuperOps SCIM export stopped (Sync 1)
                            @endif
                        </p>
                        @if($scimHealth['error'] ?? null)
                            <p>{{ $scimHealth['error'] }}</p>
                        @elseif($scimNeedsApply)
                            <p class="text-red-100/90">
                                Portal Sync 2 can still update M365 and the portal, but new requesters will not reach
                                SuperOps until you paste Tenant URL + Secret Token below.
                            </p>
                        @else
                            <p class="text-red-100/90">
                                Credentials are in Entra but export is not active. Retry recreates the job and starts
                                provisioning — no secret re-paste, no Azure UI.
                            </p>
                        @endif
                        @if(! empty($scimHealth['warnings']) && is_array($scimHealth['warnings']))
                            <ul class="mt-2 list-disc space-y-1 pl-5 text-red-100/80">
                                @foreach(array_slice($scimHealth['warnings'], 0, 4) as $warning)
                                    <li>{{ $warning }}</li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                    @if($canRetryScimExport)
                        <form method="POST" action="{{ route('admin.clients.retry-scim-export', $client) }}" class="shrink-0">
                            @csrf
                            <button type="submit" class="cta-btn text-sm px-5 py-2.5 whitespace-nowrap" @disabled($scimRepairInFlight || $scimInFlight)>
                                @if($scimRepairInFlight) Retry running… @else Retry SCIM export @endif
                            </button>
                        </form>
                    @endif
                </div>
            </div>
        @endif

        @if($scimInFlight)
            <div class="mb-4 rounded border border-amber-500/40 bg-amber-500/10 px-4 py-3 text-sm leading-relaxed text-amber-100">
                Apply SCIM is running in the background. Refresh in about a minute — this step stays <strong class="text-white/90">Failed</strong> until export is active.
            </div>
        @elseif($scimRepairInFlight)
            <div class="mb-4 rounded border border-amber-500/40 bg-amber-500/10 px-4 py-3 text-sm leading-relaxed text-amber-100">
                Retry SCIM export is running in the background. Refresh in about a minute — check Integration Health → SuperOps SCIM.
            </div>
        @endif

        @if(is_array($scimLastResult) && ! $scimInFlight)
            <div @class([
                'mb-4 rounded border px-4 py-3 text-sm leading-relaxed',
                'border-emerald-500/40 bg-emerald-500/10 text-emerald-100' => ($scimLastResult['success'] ?? false) || ($scimLastResult['step_complete'] ?? false),
                'border-red-500/40 bg-red-500/10 text-red-100' => ! (($scimLastResult['success'] ?? false) || ($scimLastResult['step_complete'] ?? false)),
            ])>
                <p class="portal-label mb-1">Last Apply SCIM result</p>
                <p>{{ $scimLastResult['message'] ?? 'Finished' }}</p>
                @if(! empty($scimLastResult['blockers']) && is_array($scimLastResult['blockers']))
                    <p class="mt-2 opacity-90">{{ implode(' ', $scimLastResult['blockers']) }}</p>
                @endif
            </div>
        @endif

        @if(is_array($scimRepairLastResult) && ! $scimRepairInFlight)
            <div @class([
                'mb-4 rounded border px-4 py-3 text-sm leading-relaxed',
                'border-emerald-500/40 bg-emerald-500/10 text-emerald-100' => ($scimRepairLastResult['success'] ?? false),
                'border-red-500/40 bg-red-500/10 text-red-100' => ! ($scimRepairLastResult['success'] ?? false),
            ])>
                <p class="portal-label mb-1">Last Retry SCIM export result</p>
                <p>{{ $scimRepairLastResult['message'] ?? 'Finished' }}</p>
                @if(! empty($scimRepairLastResult['blockers']) && is_array($scimRepairLastResult['blockers']))
                    <p class="mt-2 opacity-90">{{ implode(' ', $scimRepairLastResult['blockers']) }}</p>
                @endif
            </div>
        @endif

        @if(! $scimExportBroken)
            <div class="mb-4 rounded border border-emerald-500/30 bg-emerald-500/5 px-4 py-3 text-sm leading-relaxed text-white/80">
                <p class="portal-label mb-2 text-emerald-300/90">Already automatic</p>
                <p>
                    Entra app <strong class="text-white/90">SuperOps - {{ $client->name }}</strong> (Connect),
                    Start provisioning, and P1 group assign when bootstrap succeeds.
                    Apply only marks this step <strong class="text-white/90">Done</strong> when
                    <strong class="text-white/90">name.familyName ← extensionAttribute1</strong> is set
                    <strong class="text-white/90">and</strong> Entra SCIM export is active.
                </p>
            </div>
        @endif

        @if($scimCredentialsInEntra)
            <div class="mb-4 rounded border border-emerald-500/35 bg-emerald-500/10 px-4 py-3 text-sm leading-relaxed text-emerald-100">
                <p class="portal-label mb-1 text-emerald-200/90">Credentials saved in Entra (not shown here)</p>
                <p>
                    Tenant URL host <strong class="text-white/90">{{ $scimBaseAddressHost ?: 'configured' }}</strong>
                    and secret token were written to Entra when you ran Apply SCIM.
                    The portal does <strong class="text-white/90">not</strong> keep the secret — empty fields below are normal.
                    @if($canRetryScimExport)
                        Use <strong class="text-white/90">Retry SCIM export</strong> above; no re-paste unless SuperOps rotated tokens.
                    @endif
                </p>
            </div>
        @endif

        <details class="border border-white/10 bg-white/[0.02] px-4 py-3" @if($scimNeedsApply || ! $scimCredentialsInEntra) open @endif>
            <summary class="cursor-pointer select-none text-sm font-condensed uppercase tracking-wide text-onit">
                @if($scimCredentialsInEntra)
                    Re-Apply SCIM tokens (optional — token rotation only)
                @else
                    Apply SCIM credentials + start
                @endif
            </summary>

            <div class="mt-4 space-y-4">
                <p class="text-sm leading-relaxed text-white/75">
                    Paste SuperOps <strong class="text-white/90">Tenant URL</strong> and <strong class="text-white/90">Secret Token</strong>
                    from step 05 Generate Tokens. Only needed the first time, or if SuperOps re-generated tokens.
                </p>

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
                    </p>
                </form>
            </div>
        </details>
    </div>
@elseif(isset($client) && $client->exists && filled($client->entra_tenant_id) && blank($client->entra_superops_app_id))
    <p class="portal-body-muted @if(! $inStep) mt-6 @endif text-xs">
        Connect Microsoft / bootstrap first so SuperOps SCIM Application (client) ID is saved.
    </p>
@endif
