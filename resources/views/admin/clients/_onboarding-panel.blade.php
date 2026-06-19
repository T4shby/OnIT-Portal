<x-card class="onboarding-panel lg:sticky lg:top-8">
    <div class="mb-6">
        <p class="portal-label mb-2">Client setup guide</p>
        <p class="portal-body-muted text-xs mb-4">
            @if($client->exists)
                Follow these steps to connect M365, SuperOps, and the portal. Tick manual steps when done.
            @else
                Full setup instructions — create the client first, then return to <strong class="text-white/70">Edit</strong> to save checklist progress and generate the admin consent URL.
            @endif
        </p>

        @if($client->exists)
            <div class="flex items-center justify-between gap-4 mb-2">
                <span class="portal-card-title text-sm">{{ $onboardingProgress['complete'] }} / {{ $onboardingProgress['total'] }} complete</span>
                <span class="font-condensed text-sm font-bold text-onit">{{ $onboardingProgress['percent'] }}%</span>
            </div>
            <div class="h-1.5 w-full bg-white/10">
                <div class="h-full bg-onit transition-all duration-300" style="width: {{ $onboardingProgress['percent'] }}%"></div>
            </div>
        @endif
    </div>

    @if($client->exists)
        <form method="POST" action="{{ route('admin.clients.onboarding.update', $client) }}">
            @csrf
            @method('PUT')
            @include('admin.clients._onboarding-steps', [
                'client' => $client,
                'onboardingSteps' => $onboardingSteps,
                'adminConsentUrl' => $adminConsentUrl,
                'showCheckboxes' => true,
            ])
            <button type="submit" class="cta-btn text-sm px-6 py-3 mt-6 w-full sm:w-auto">Save checklist</button>
        </form>
    @else
        @include('admin.clients._onboarding-steps', [
            'client' => $client,
            'onboardingSteps' => $onboardingSteps,
            'adminConsentUrl' => $adminConsentUrl,
            'showCheckboxes' => false,
            'expandAll' => true,
        ])
    @endif
</x-card>
