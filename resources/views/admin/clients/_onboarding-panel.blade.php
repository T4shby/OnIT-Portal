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
                <strong class="text-white/80">Dry run sync</strong> and <strong class="text-white/80">Sync now</strong> are on the left under Microsoft Entra sync.
            @else
                Fill in the form on the left and click <strong class="text-white/80">Create</strong>.
                Step statuses below are a <strong class="text-white/80">preview only</strong> — they update after the client is saved.
                Open <strong class="text-white/80">Edit</strong> to track progress, tick manual steps, and use the admin consent URL.
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
        <div class="onboarding-panel__body onboarding-panel__body--preview">
            <p class="onboarding-guide__note portal-body-muted mb-5 text-sm">
                Checklist progress is not tracked on this page. Nothing is saved until you click <strong class="text-white/80">Create</strong>.
            </p>
            <ol class="onboarding-preview-list">
                @foreach($onboardingSteps as $index => $step)
                    <li>
                        <span class="onboarding-guide__num" aria-hidden="true">{{ str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) }}</span>
                        <span class="agenda-title">{{ $step['title'] }}</span>
                    </li>
                @endforeach
            </ol>
        </div>
    @endif
</div>
