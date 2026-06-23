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

        <p class="portal-body-muted max-w-prose text-sm leading-relaxed">
            <strong class="text-white/80">Complete start-to-finish onboarding for this customer.</strong>
            Expand each numbered step below — every URL, click path, and server command is listed there.
            Work through the steps in order from 00 to 11.
        </p>
        <p class="portal-body-muted mt-4 max-w-prose text-sm leading-relaxed">
            <strong class="text-white/80">Recommended order:</strong>
            Platform Graph (00, once) → Portal record (01) → SuperOps link (02) → Pax8 if used (03) →
            M365 group in customer Entra (04) → Admin consent (05) → SuperOps SCIM (06) → SuperOps SAML (07) →
            Enable sync on left (08) → Dry run / Sync now (09) → Test sign-in (10) → Hand off (11).
        </p>
        <p class="portal-body-muted mt-4 max-w-prose text-sm leading-relaxed">
            <strong class="text-white/80">Saving data:</strong>
            Entra tenant ID, group ID, and sync settings are saved with the orange
            <strong class="text-white/80">Save client</strong> button on the left — not Save checklist.
            Save checklist stores manual ticks for steps done in Azure or SuperOps.
        </p>

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

    @if($hasManualCheckboxes)
        <form
            method="POST"
            action="{{ route('admin.clients.onboarding.update', $client) }}"
            class="onboarding-panel__body"
        >
            @csrf
            @method('PUT')
            @include('admin.clients._onboarding-steps', [
                'client' => $client,
                'onboardingSteps' => $onboardingSteps,
                'adminConsentUrl' => $adminConsentUrl,
                'showCheckboxes' => true,
            ])
            <div class="onboarding-panel__actions">
                <button type="submit" class="cta-btn text-sm">Save checklist</button>
            </div>
        </form>
    @else
        <div class="onboarding-panel__body">
            @include('admin.clients._onboarding-steps', [
                'client' => $client,
                'onboardingSteps' => $onboardingSteps,
                'adminConsentUrl' => $adminConsentUrl,
                'showCheckboxes' => false,
            ])
        </div>
    @endif
</div>
