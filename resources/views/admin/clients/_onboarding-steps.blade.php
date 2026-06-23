@php
    $defaultOpenIndex = 0;
    foreach ($onboardingSteps as $i => $step) {
        if (! $step['complete'] && ! $step['blocked']) {
            $defaultOpenIndex = $i;
            break;
        }
    }
@endphp

<div
    class="onboarding-guide"
    x-data="{ openStep: {{ $defaultOpenIndex }} }"
>
    @foreach($onboardingSteps as $index => $step)
        @php
            $stepNumber = str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT);
            $isDone = $step['complete'];
            $isBlocked = $step['blocked'] && ! $step['complete'];
        @endphp

        <article
            class="onboarding-guide__item @if($isDone) is-done @endif @if($isBlocked) is-blocked @endif"
            :class="{ 'is-open': openStep === {{ $index }} }"
        >
            <button
                type="button"
                class="onboarding-guide__trigger"
                @click="openStep = openStep === {{ $index }} ? -1 : {{ $index }}"
                :aria-expanded="openStep === {{ $index }}"
            >
                <span class="onboarding-guide__num" aria-hidden="true">{{ $stepNumber }}</span>

                <span class="onboarding-guide__copy">
                    <span class="agenda-title">{{ $step['title'] }}</span>
                    <span class="onboarding-guide__meta">{{ $step['who'] }}</span>
                </span>

                @if($isDone)
                    <span class="onboarding-status onboarding-status--done">Done</span>
                @elseif($isBlocked)
                    <span class="onboarding-status onboarding-status--blocked">Blocked</span>
                @else
                    <span class="onboarding-status onboarding-status--pending">Pending</span>
                @endif

                <svg
                    class="onboarding-guide__chevron"
                    :class="{ 'is-open': openStep === {{ $index }} }"
                    fill="none"
                    stroke="currentColor"
                    viewBox="0 0 24 24"
                    aria-hidden="true"
                >
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                </svg>
            </button>

            <div
                x-show="openStep === {{ $index }}"
                x-cloak
                class="onboarding-guide__panel"
            >
                <ul class="support-list">
                    @foreach($step['instructions'] as $instruction)
                        <li>{{ $instruction }}</li>
                    @endforeach
                </ul>

                @if($step['key'] === 'entra_admin_consent_granted' && $adminConsentUrl)
                    <div class="onboarding-guide__extra" x-data="{ copied: false }">
                        <p class="portal-label mb-3">Admin consent URL</p>
                        <div class="flex flex-col gap-3 lg:flex-row">
                            <input
                                type="text"
                                readonly
                                value="{{ $adminConsentUrl }}"
                                class="admin-input flex-1 text-xs"
                            >
                            <div class="flex shrink-0 gap-2">
                                <a
                                    href="{{ $adminConsentUrl }}"
                                    target="_blank"
                                    rel="noopener"
                                    class="cta-btn-ghost px-4 py-2 text-xs"
                                >Open</a>
                                <button
                                    type="button"
                                    class="cta-btn-ghost px-4 py-2 text-xs"
                                    @click="navigator.clipboard.writeText(@js($adminConsentUrl)); copied = true; setTimeout(() => copied = false, 2000)"
                                    x-text="copied ? 'Copied' : 'Copy'"
                                >Copy</button>
                            </div>
                        </div>
                    </div>
                @elseif($step['key'] === 'entra_admin_consent_granted' && ! $adminConsentUrl)
                    <p class="onboarding-guide__note portal-body-muted text-sm">
                        Save the Entra tenant ID on the left to generate the consent link here.
                    </p>
                @endif

                @if($isDone && ($step['auto_detected'] ?? false))
                    <p class="onboarding-guide__note portal-body-muted text-sm">
                        Completed automatically from saved client details.
                    </p>
                @endif

                @if(($showCheckboxes ?? false) && $step['manual'] && ! $isBlocked && ! $isDone)
                    <label class="onboarding-guide__check">
                        <input
                            type="checkbox"
                            name="checkpoints[{{ $step['key'] }}]"
                            value="1"
                            class="border-onit-border bg-onit-surface text-onit focus:ring-onit"
                        >
                        <span>Mark this step complete</span>
                    </label>
                @endif
            </div>
        </article>
    @endforeach
</div>
