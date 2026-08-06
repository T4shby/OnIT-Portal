@php
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
            $showGraphAccept = in_array($step['key'], ['entra_group_created', 'entra_admin_consent_granted'], true)
                && ! $isDone
                && ! $isBlocked;
            $showScimApply = ($step['key'] ?? '') === 'superops_scim_provisioning' && ! $isBlocked;
            $showClientSsoApply = ($step['key'] ?? '') === 'superops_client_sso_configured' && ! $isBlocked;
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
                @if($showGraphAccept)
                    @if(! empty($adminConsentUrl))
                        <div class="onboarding-guide__extra mb-4" x-data="{ copied: false }">
                            <p class="portal-label mb-2">Start here</p>
                            <a
                                href="{{ $adminConsentUrl }}"
                                target="_blank"
                                rel="noopener"
                                class="cta-btn inline-flex w-full items-center justify-center px-5 py-3 text-center text-sm sm:w-auto"
                            >Connect Microsoft tenant</a>
                            <div class="mt-3 flex flex-col gap-2 sm:flex-row sm:items-center">
                                <input
                                    type="text"
                                    readonly
                                    value="{{ $adminConsentUrl }}"
                                    class="admin-input min-w-0 flex-1 text-xs"
                                    aria-label="Portal Graph Accept URL"
                                >
                                <button
                                    type="button"
                                    class="cta-btn-ghost shrink-0 px-4 py-2 text-xs"
                                    @click="navigator.clipboard.writeText(@js($adminConsentUrl)); copied = true; setTimeout(() => copied = false, 2000)"
                                    x-text="copied ? 'Copied' : 'Copy link'"
                                >Copy link</button>
                            </div>
                        </div>
                    @else
                        <p class="onboarding-guide__note mb-4 text-sm text-onit border border-onit/40 bg-onit/10 rounded px-4 py-3">
                            Portal Microsoft app client ID is not configured. Add MICROSOFT_CLIENT_ID to .env.
                        </p>
                    @endif
                @endif

                @if($showScimApply)
                    <div class="onboarding-guide__extra mb-4">
                        @include('admin.clients._scim-apply-form', [
                            'client' => $client,
                            'inStep' => true,
                        ])
                    </div>
                @endif

                @if($showClientSsoApply)
                    <div class="onboarding-guide__extra mb-4">
                        @include('admin.clients._client-sso-apply-form', [
                            'client' => $client,
                            'inStep' => true,
                        ])
                    </div>
                @endif

                @include('admin.clients._onboarding-manual', [
                    'guide' => $step['guide'] ?? null,
                    'instructions' => $step['instructions'] ?? [],
                    'openRecovery' => session()->has('error')
                        || (isset($errors) && $step['key'] === 'superops_client_sso_configured' && ($errors->has('entity_id') || $errors->has('consumer_service_url')))
                        || (isset($errors) && $step['key'] === 'superops_scim_provisioning' && ($errors->has('scim_tenant_url') || $errors->has('scim_secret_token'))),
                ])

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
                            form="onboarding-checklist-form"
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
