@php
    $defaultOpenIndex = 0;
    foreach ($onboardingSteps as $i => $step) {
        if (! $step['complete'] && ! $step['blocked']) {
            $defaultOpenIndex = $i;
            break;
        }
    }
@endphp

<ol class="onboarding-steps">
    @foreach($onboardingSteps as $index => $step)
        <li @class([
            'onboarding-step',
            'onboarding-step--complete' => $step['complete'],
            'onboarding-step--blocked' => $step['blocked'] && ! $step['complete'],
            'onboarding-step--open' => $index === $defaultOpenIndex,
        ])>
            <details @if($index === $defaultOpenIndex) open @endif class="onboarding-step__details">
                <summary class="onboarding-step__summary">
                    <span class="onboarding-step__number" aria-hidden="true">{{ $index + 1 }}</span>
                    <span class="onboarding-step__head">
                        <span class="onboarding-step__title">{{ $step['title'] }}</span>
                        <span class="onboarding-step__who">{{ $step['who'] }}</span>
                    </span>
                    @if($step['complete'])
                        <span class="onboarding-step__badge onboarding-step__badge--done">Done</span>
                    @elseif($step['blocked'])
                        <span class="onboarding-step__badge onboarding-step__badge--blocked">Blocked</span>
                    @else
                        <span class="onboarding-step__badge onboarding-step__badge--pending">Pending</span>
                    @endif
                    <svg class="onboarding-step__chevron" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                    </svg>
                </summary>

                <div class="onboarding-step__body">
                    <ol class="onboarding-step__instructions">
                        @foreach($step['instructions'] as $instruction)
                            <li>{{ $instruction }}</li>
                        @endforeach
                    </ol>

                    @if($step['key'] === 'entra_admin_consent_granted' && $adminConsentUrl)
                        <div class="onboarding-step__consent" x-data="{ copied: false }">
                            <p class="portal-label mb-2">Admin consent URL</p>
                            <div class="flex flex-col gap-2 sm:flex-row">
                                <input
                                    type="text"
                                    readonly
                                    value="{{ $adminConsentUrl }}"
                                    class="admin-input flex-1 text-xs"
                                >
                                <a
                                    href="{{ $adminConsentUrl }}"
                                    target="_blank"
                                    rel="noopener"
                                    class="cta-btn-ghost text-xs px-4 py-2 whitespace-nowrap text-center"
                                >Open</a>
                                <button
                                    type="button"
                                    class="cta-btn-ghost text-xs px-4 py-2 whitespace-nowrap"
                                    @click="navigator.clipboard.writeText(@js($adminConsentUrl)); copied = true; setTimeout(() => copied = false, 2000)"
                                    x-text="copied ? 'Copied' : 'Copy'"
                                >Copy</button>
                            </div>
                        </div>
                    @elseif($step['key'] === 'entra_admin_consent_granted' && ! $adminConsentUrl)
                        <p class="onboarding-step__hint">Save the Entra tenant ID on the left to generate the consent link here.</p>
                    @endif

                    @if(($showCheckboxes ?? false) && $step['manual'] && ! $step['blocked'])
                        <label class="onboarding-step__check">
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
