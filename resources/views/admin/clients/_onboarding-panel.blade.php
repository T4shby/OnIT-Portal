<x-card class="onboarding-panel lg:sticky lg:top-8 lg:max-h-[calc(100vh-4rem)] lg:overflow-y-auto">
    <div class="onboarding-panel__header">
        <p class="portal-label">Client setup guide</p>
        <p class="portal-body-muted mt-2 text-sm leading-relaxed">
            @if($client->exists)
                Connect M365, SuperOps, and the portal. Expand each step — only one open at a time. Tick manual steps when done.
            @else
                Create the client first, then return to <strong class="text-white/80">Edit</strong> to save checklist progress and generate the admin consent URL.
            @endif
        </p>

        @if($client->exists)
            <div class="onboarding-panel__progress">
                <div class="flex items-center justify-between gap-4">
                    <span class="font-condensed text-sm font-bold uppercase tracking-wide text-white">
                        {{ $onboardingProgress['complete'] }} / {{ $onboardingProgress['total'] }} complete
                    </span>
                    <span class="font-condensed text-sm font-bold text-onit">{{ $onboardingProgress['percent'] }}%</span>
                </div>
                <div class="onboarding-panel__progress-bar">
                    <div class="onboarding-panel__progress-fill" style="width: {{ $onboardingProgress['percent'] }}%"></div>
                </div>
            </div>
        @endif
    </div>

    @if($client->exists)
        <form method="POST" action="{{ route('admin.clients.onboarding.update', $client) }}" class="onboarding-panel__form">
            @csrf
            @method('PUT')
            @include('admin.clients._onboarding-steps', [
                'client' => $client,
                'onboardingSteps' => $onboardingSteps,
                'adminConsentUrl' => $adminConsentUrl,
                'showCheckboxes' => true,
            ])
            <button type="submit" class="cta-btn mt-6 w-full text-sm sm:w-auto">Save checklist</button>
        </form>
    @else
        @include('admin.clients._onboarding-steps', [
            'client' => $client,
            'onboardingSteps' => $onboardingSteps,
            'adminConsentUrl' => $adminConsentUrl,
            'showCheckboxes' => false,
        ])
    @endif
</x-card>
