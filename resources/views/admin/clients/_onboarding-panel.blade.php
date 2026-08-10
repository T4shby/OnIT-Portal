@php
    $hasManualCheckboxes = collect($onboardingSteps)->contains(
        fn (array $step) => $step['manual'] && ! $step['complete'] && ! ($step['blocked'] && ! $step['complete'])
    );
@endphp

<div class="onboarding-panel lg:sticky lg:top-8 lg:max-h-[calc(100vh-4rem)] lg:overflow-y-auto">
    <div class="onboarding-panel__header">
        <div class="orange-rule"></div>
        <div class="heading-stack mb-5">
            <h2 class="section-heading-white !text-[1.35rem] sm:!text-[1.6rem]">Client setup</h2>
            <h2 class="section-heading-orange !text-[1.35rem] sm:!text-[1.6rem]">Guide</h2>
        </div>

        <p class="portal-body-muted text-sm leading-relaxed">
            Work the open step on the right. Action forms for SCIM (07) and Client SSO (08) live inside those steps.
            <strong class="text-white/80">Save client</strong> (left) only stores IDs and flags.
        </p>

        @if(! empty($adminConsentUrl))
            <div class="mt-4 space-y-3">
                @if(! filled($client->entra_tenant_id))
                    <a
                        href="{{ $adminConsentUrl }}"
                        target="_blank"
                        rel="noopener"
                        class="cta-btn inline-flex w-full items-center justify-center px-5 py-3 text-center text-sm"
                    >Connect Microsoft tenant</a>
                    <p class="portal-body-muted text-xs leading-relaxed">
                        Only button that opens Microsoft Accept (private browser · GDAP into
                        <strong class="text-white/80">{{ $client->name }}</strong>).
                        After Accept the portal waits for Graph and creates group + SuperOps app IDs automatically.
                    </p>
                @else
                    <form method="POST" action="{{ route('admin.clients.bootstrap-entra', $client) }}">
                        @csrf
                        <button type="submit" class="cta-btn w-full px-5 py-3 text-center text-sm">
                            Retry Graph setup
                        </button>
                    </form>
                    <p class="portal-body-muted text-xs leading-relaxed">
                        Tenant ID is saved. Use this only if group or SuperOps app IDs are still empty
                        (Azure lag after Accept — not a second Microsoft login).
                    </p>
                    <a
                        href="{{ $adminConsentUrl }}"
                        target="_blank"
                        rel="noopener"
                        class="cta-btn-ghost inline-flex w-full items-center justify-center px-5 py-3 text-center text-sm"
                    >Re-consent Graph permissions</a>
                    <p class="portal-body-muted text-xs leading-relaxed">
                        Private browser · GDAP into <strong class="text-white/80">{{ $client->name }}</strong>.
                        Use after On IT adds Application permissions (Secure Score / MFA, etc.).
                        Does <strong class="text-white/80">not</strong> require redoing SCIM or Client SSO.
                    </p>
                @endif
            </div>
        @endif

        <div class="onboarding-panel__progress">
            <div class="flex items-end justify-between gap-4">
                <div>
                    <p class="portal-label mb-1">Progress</p>
                    <p class="font-condensed text-lg font-bold uppercase tracking-wide text-white">
                        {{ $onboardingProgress['complete'] }} of {{ $onboardingProgress['total'] }} steps
                    </p>
                </div>
                <p class="font-condensed text-2xl font-extrabold text-onit">{{ $onboardingProgress['percent'] }}%</p>
            </div>
            <div class="onboarding-panel__progress-bar">
                <div class="onboarding-panel__progress-fill" style="width: {{ $onboardingProgress['percent'] }}%"></div>
            </div>
        </div>
    </div>

    {{-- Checklist form sits empty here so step action forms (SCIM/SSO) are not nested. Checkboxes use form="…". --}}
    <form
        id="onboarding-checklist-form"
        method="POST"
        action="{{ route('admin.clients.onboarding.update', $client) }}"
        class="hidden"
        aria-hidden="true"
    >
        @csrf
        @method('PUT')
    </form>

    <div class="onboarding-panel__body">
        @include('admin.clients._onboarding-steps', [
            'client' => $client,
            'onboardingSteps' => $onboardingSteps,
            'adminConsentUrl' => $adminConsentUrl,
            'showCheckboxes' => $hasManualCheckboxes,
        ])
    </div>

    @if($hasManualCheckboxes)
        <div class="onboarding-panel__actions">
            <button type="submit" form="onboarding-checklist-form" class="cta-btn text-sm">Save checklist</button>
            <p class="portal-body-muted mt-2 text-xs leading-relaxed">
                Tick “Mark this step complete” and it saves immediately.
                SCIM / Client SSO action buttons are inside steps 07 and 08.
            </p>
        </div>
        <script>
            (function () {
                var form = document.getElementById('onboarding-checklist-form');
                if (!form) return;
                document.querySelectorAll('input[type="checkbox"][name^="checkpoints"][form="onboarding-checklist-form"]').forEach(function (box) {
                    box.addEventListener('change', function () {
                        if (typeof form.requestSubmit === 'function') {
                            form.requestSubmit();
                        } else {
                            form.submit();
                        }
                    });
                });
            })();
        </script>
    @endif
</div>
