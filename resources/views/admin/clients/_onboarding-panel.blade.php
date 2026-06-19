<div class="onboarding-panel lg:sticky lg:top-8 lg:max-h-[calc(100vh-4rem)] lg:overflow-y-auto">
    <div class="onboarding-panel__header">
        <div class="orange-rule"></div>
        <div class="heading-stack mb-5">
            <h2 class="section-heading-white !text-[1.35rem] sm:!text-[1.6rem]">Client setup</h2>
            <h2 class="section-heading-orange !text-[1.35rem] sm:!text-[1.6rem]">Guide</h2>
        </div>

        <p class="portal-body-muted max-w-prose text-sm leading-relaxed">
            @if($client->exists)
                Work through each step in order. Click a step to expand the instructions. Tick manual checkpoints when complete.
            @else
                Create the client on the left first. Return to <strong class="text-white/80">Edit</strong> to track progress and generate the admin consent URL.
            @endif
        </p>

        @if($client->exists)
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
        @endif
    </div>

    @if($client->exists)
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
