<x-card class="onboarding-panel lg:sticky lg:top-8">
    <div class="mb-6">
        <p class="portal-label mb-2">Client setup</p>
        <p class="portal-body-muted text-xs mb-4">Follow these steps to connect M365, SuperOps, and the portal. Tick manual steps when done.</p>

        <div class="flex items-center justify-between gap-4 mb-2">
            <span class="portal-card-title text-sm">{{ $onboardingProgress['complete'] }} / {{ $onboardingProgress['total'] }} complete</span>
            <span class="font-condensed text-sm font-bold text-onit">{{ $onboardingProgress['percent'] }}%</span>
        </div>
        <div class="h-1.5 w-full bg-white/10">
            <div class="h-full bg-onit transition-all duration-300" style="width: {{ $onboardingProgress['percent'] }}%"></div>
        </div>
    </div>

    <form method="POST" action="{{ route('admin.clients.onboarding.update', $client) }}">
        @csrf
        @method('PUT')

        <ol class="space-y-3">
            @foreach($onboardingSteps as $index => $step)
                <li @class([
                    'onboarding-step',
                    'onboarding-step--complete' => $step['complete'],
                    'onboarding-step--blocked' => $step['blocked'] && ! $step['complete'],
                ])>
                    <details @if(! $step['complete'] && ! $step['blocked']) open @endif>
                        <summary class="onboarding-step__summary">
                            <span class="onboarding-step__number">{{ $index + 1 }}</span>
                            <span class="flex-1 min-w-0">
                                <span class="onboarding-step__title">{{ $step['title'] }}</span>
                                <span class="onboarding-step__who">{{ $step['who'] }}</span>
                            </span>
                            @if($step['complete'])
                                <span class="onboarding-step__badge onboarding-step__badge--done">Done</span>
                            @elseif($step['blocked'])
                                <span class="onboarding-step__badge onboarding-step__badge--blocked">Blocked</span>
                            @else
                                <span class="onboarding-step__badge">Pending</span>
                            @endif
                        </summary>

                        <div class="onboarding-step__body">
                            <ol class="onboarding-step__instructions">
                                @foreach($step['instructions'] as $instruction)
                                    <li>{{ $instruction }}</li>
                                @endforeach
                            </ol>

                            @if($step['key'] === 'entra_admin_consent_granted' && $adminConsentUrl)
                                <div class="mt-4 space-y-2" x-data="{ copied: false }">
                                    <p class="portal-label text-[10px]">Admin consent URL</p>
                                    <div class="flex flex-col gap-2 sm:flex-row">
                                        <input
                                            type="text"
                                            readonly
                                            value="{{ $adminConsentUrl }}"
                                            class="admin-input flex-1 text-xs"
                                            id="admin-consent-url"
                                        >
                                        <a
                                            href="{{ $adminConsentUrl }}"
                                            target="_blank"
                                            rel="noopener"
                                            class="cta-btn-ghost text-xs px-4 py-2 whitespace-nowrap"
                                        >Open</a>
                                        <button
                                            type="button"
                                            class="cta-btn-ghost text-xs px-4 py-2 whitespace-nowrap"
                                            @click="navigator.clipboard.writeText(@js($adminConsentUrl)); copied = true; setTimeout(() => copied = false, 2000)"
                                            x-text="copied ? 'Copied' : 'Copy'"
                                        >Copy</button>
                                    </div>
                                </div>
                            @endif

                            @if($step['manual'] && ! $step['blocked'])
                                <label class="onboarding-step__check mt-4">
                                    <input
                                        type="checkbox"
                                        name="checkpoints[{{ $step['key'] }}]"
                                        value="1"
                                        @checked($client->onboarding_checklist[$step['key']] ?? false)
                                        class="rounded border-onit-border bg-onit-surface text-onit focus:ring-onit"
                                    >
                                    <span>Mark as complete</span>
                                </label>
                            @endif
                        </div>
                    </details>
                </li>
            @endforeach
        </ol>

        <button type="submit" class="cta-btn text-sm px-6 py-3 mt-6 w-full sm:w-auto">Save checklist</button>
    </form>
</x-card>
