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
            Per-client checklist for On IT technicians. <strong class="text-white/80">Not every step is on this page.</strong>
            Use the checklist for order and ticks; work in the system named on each step.
        </p>
        <ul class="support-list mt-4 mb-5 text-sm portal-body-muted">
            <li><strong class="text-white/80">app.onit.ltd</strong> (this page) — client fields, consent URL, Dry run / Sync now, checklist ticks</li>
            <li><strong class="text-white/80">portal.azure.com</strong> (customer tenant) — security group, SuperOps Entra app, admin consent</li>
            <li><strong class="text-white/80">SuperOps MSP console</strong> — SCIM tokens, Client SSO config, verify requesters</li>
            <li><strong class="text-white/80">portal.azure.com</strong> (On IT tenant, once per platform) — Graph permissions on OnIT Portal for Portals — already done if all 9 permissions show Granted</li>
        </ul>
        <p class="portal-body-muted max-w-prose text-sm leading-relaxed">
            Saved fields on the left complete automatically after <strong class="text-white/80">Update</strong>.
            Manual steps: tick the box and click <strong class="text-white/80">Save checklist</strong>.
            Full reference: Brain/CustomerEntraSyncRunbook.md in the repo.
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
        <form method="POST" action="{{ route('admin.clients.onboarding.update', $client) }}" class="onboarding-panel__body">
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
