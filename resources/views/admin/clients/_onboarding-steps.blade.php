@php
    $isEntraFree = ($client->entra_license_tier ?? 'free') === 'free';
    $defaultOpenIndex = 0;
    foreach ($onboardingSteps as $i => $step) {
        if (! $step['complete'] && ! $step['blocked']) {
            $defaultOpenIndex = $i;
            break;
        }
    }
@endphp

<div
    class="onboarding-guide"
    x-data="{ openStep: {{ $defaultOpenIndex }} }"
>
    @foreach($onboardingSteps as $index => $step)
        @php
            $stepNumber = str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT);
            $isDone = $step['complete'];
            $isBlocked = $step['blocked'] && ! $step['complete'];
        @endphp

        <article
            class="onboarding-guide__item @if($isDone) is-done @endif @if($isBlocked) is-blocked @endif"
            :class="{ 'is-open': openStep === {{ $index }} }"
        >
            <button
                type="button"
                class="onboarding-guide__trigger"
                @click="openStep = openStep === {{ $index }} ? -1 : {{ $index }}"
                :aria-expanded="openStep === {{ $index }}"
            >
                <span class="onboarding-guide__num" aria-hidden="true">{{ $stepNumber }}</span>

                <span class="onboarding-guide__copy">
                    <span class="agenda-title">{{ $step['title'] }}</span>
                    <span class="onboarding-guide__meta">{{ $step['who'] }}</span>
                </span>

                @if($isDone)
                    <span class="onboarding-status onboarding-status--done">Done</span>
                @elseif($isBlocked)
                    <span class="onboarding-status onboarding-status--blocked">Blocked</span>
                @else
                    <span class="onboarding-status onboarding-status--pending">Pending</span>
                @endif

                <svg
                    class="onboarding-guide__chevron"
                    :class="{ 'is-open': openStep === {{ $index }} }"
                    fill="none"
                    stroke="currentColor"
                    viewBox="0 0 24 24"
                    aria-hidden="true"
                >
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                </svg>
            </button>

            <div
                x-show="openStep === {{ $index }}"
                x-cloak
                class="onboarding-guide__panel"
            >
                @include('admin.clients._onboarding-manual', [
                    'guide' => $step['guide'] ?? null,
                    'instructions' => $step['instructions'] ?? [],
                ])

                @if($step['key'] === 'entra_group_created' && ! $isDone && ! $isBlocked)
                    <p class="onboarding-guide__note portal-body-muted text-sm">
                        Paste <strong class="text-white/80">Entra tenant ID</strong> and <strong class="text-white/80">Entra group ID</strong> on the left → <strong class="text-white/80">Save client</strong>.
                        @if($isEntraFree)
                            On Entra ID Free the group is still required — read <strong class="text-white/80">Why this group on Entra ID Free?</strong> in the step notes. Do not add members in Azure; <strong class="text-white/80">Sync now</strong> (step 08) fills the group for you.
                        @else
                            Leave the group empty in Azure — <strong class="text-white/80">Sync now</strong> (step 08) fills members for you.
                        @endif
                    </p>
                @endif

                @if($step['key'] === 'portal_sync_run' && ! $isDone && ! $isBlocked)
                    <p class="onboarding-guide__note mb-4 text-sm text-onit border border-onit/40 bg-onit/10 rounded px-4 py-3">
                        <strong class="text-white">After you click Sync now:</strong>
                        tick <strong class="text-white">Mark this step complete</strong> below → <strong class="text-white">Save checklist</strong>.
                        The step also turns Done when <strong class="text-white">Last synced</strong> appears on the left.
                    </p>
                @endif

                @if($step['key'] === 'superops_client_sso_configured' && ! $isDone && ! $isBlocked)
                    <p class="onboarding-guide__note mb-4 text-sm text-onit border border-onit/40 bg-onit/10 rounded px-4 py-3">
                        <strong class="text-white">Per client — customer GA must Accept.</strong>
                        Platform Global SSO (cert / Entity ID) is already set once. This step is the customer tenant
                        <strong class="text-white">Accept</strong> of <strong class="text-white">SuperOps Requester SSO (On IT)</strong>
                        — not step 04 Portal Graph consent, and not SuperOps Client SSO.
                    </p>
                    @if(! empty($superOpsRequesterSsoConsentUrl))
                        <div class="onboarding-guide__extra mb-4" x-data="{ copied: false }">
                            <p class="portal-label mb-3">SuperOps SSO Accept URL</p>
                            <div class="flex flex-col gap-3 lg:flex-row">
                                <input
                                    type="text"
                                    readonly
                                    value="{{ $superOpsRequesterSsoConsentUrl }}"
                                    class="admin-input flex-1 text-xs"
                                >
                                <div class="flex shrink-0 gap-2">
                                    <a
                                        href="{{ $superOpsRequesterSsoConsentUrl }}"
                                        target="_blank"
                                        rel="noopener"
                                        class="cta-btn-ghost px-4 py-2 text-xs"
                                    >Open</a>
                                    <button
                                        type="button"
                                        class="cta-btn-ghost px-4 py-2 text-xs"
                                        @click="navigator.clipboard.writeText(@js($superOpsRequesterSsoConsentUrl)); copied = true; setTimeout(() => copied = false, 2000)"
                                        x-text="copied ? 'Copied' : 'Copy'"
                                    >Copy</button>
                                </div>
                            </div>
                        </div>
                    @else
                        <p class="onboarding-guide__note portal-body-muted mb-4 text-sm">
                            Save the Entra tenant ID on the left to generate the SuperOps SSO Accept link here.
                        </p>
                    @endif
                @endif

                @if($step['key'] === 'entra_admin_consent_granted' && $adminConsentUrl)
                    <p class="onboarding-guide__note mb-4 text-sm text-onit border border-onit/40 bg-onit/10 rounded px-4 py-3">
                        <strong class="text-white">Not app.onit.ltd login.</strong>
                        Use only the Microsoft URL below. Sign in as a <strong class="text-white">{{ $client->name }}</strong> Global Admin at Microsoft → Accept.
                        If you land on <code class="text-onit">app.onit.ltd/login</code>, you opened the wrong link.
                    </p>
                    <div class="onboarding-guide__extra" x-data="{ copied: false }">
                        <p class="portal-label mb-3">Admin consent URL</p>
                        <div class="flex flex-col gap-3 lg:flex-row">
                            <input
                                type="text"
                                readonly
                                value="{{ $adminConsentUrl }}"
                                class="admin-input flex-1 text-xs"
                            >
                            <div class="flex shrink-0 gap-2">
                                <a
                                    href="{{ $adminConsentUrl }}"
                                    target="_blank"
                                    rel="noopener"
                                    class="cta-btn-ghost px-4 py-2 text-xs"
                                >Open</a>
                                <button
                                    type="button"
                                    class="cta-btn-ghost px-4 py-2 text-xs"
                                    @click="navigator.clipboard.writeText(@js($adminConsentUrl)); copied = true; setTimeout(() => copied = false, 2000)"
                                    x-text="copied ? 'Copied' : 'Copy'"
                                >Copy</button>
                            </div>
                        </div>
                    </div>
                @elseif($step['key'] === 'entra_admin_consent_granted' && ! $adminConsentUrl)
                    <p class="onboarding-guide__note portal-body-muted text-sm">
                        Save the Entra tenant ID on the left to generate the consent link here.
                    </p>
                @endif

                @if($isDone && ($step['auto_detected'] ?? false))
                    <p class="onboarding-guide__note portal-body-muted text-sm">
                        Completed automatically from saved client details.
                    </p>
                @endif

                @if(($showCheckboxes ?? false) && $step['manual'] && ! $isBlocked && ! $isDone)
                    @php
                        $checkpointSaved = (bool) (($client->onboarding_checklist ?? [])[$step['key']] ?? false);
                    @endphp
                    <label class="onboarding-guide__check">
                        <input
                            type="checkbox"
                            name="checkpoints[{{ $step['key'] }}]"
                            value="1"
                            class="border-onit-border bg-onit-surface text-onit focus:ring-onit"
                            @checked($checkpointSaved)
                        >
                        <span>Mark this step complete</span>
                    </label>
                @endif
            </div>
        </article>
    @endforeach
</div>
