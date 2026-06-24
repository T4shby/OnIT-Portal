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
            <strong class="text-white/80">Per-customer onboarding only</strong> — steps for {{ $client->name }}.
            Expand each numbered step for full instructions.
        </p>
        <p class="portal-body-muted mt-4 max-w-prose text-sm leading-relaxed">
            <strong class="text-white/80">Not on this checklist:</strong>
            one-time On IT platform setup (Graph permissions on OnIT Portal for Portals in the <strong class="text-white/80">On IT</strong> tenant, server deploy, portal OAuth app).
            That is documented in <strong class="text-white/80">Brain/CustomerEntraSyncRunbook.md</strong> — do it once, not per client.
        </p>
        <p class="portal-body-muted mt-4 max-w-prose text-sm leading-relaxed">
            <strong class="text-white/80">Order for this client:</strong>
            SuperOps link (01) → Pax8 if used (02) → M365 group (03) → Admin consent (04) →
            SuperOps SCIM (05) → SuperOps SAML (06) → Enable sync (07) → Dry run / Sync now (08) → Test sign-in (09) → Hand off (10).
        </p>
        <p class="portal-body-muted mt-4 max-w-prose text-sm leading-relaxed">
            <strong class="text-white/80">Saving data:</strong>
            Entra tenant ID, group ID, and sync settings → orange <strong class="text-white/80">Save client</strong> on the left.
            Checklist ticks → <strong class="text-white/80">Save checklist</strong> (Azure / SuperOps steps only).
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
