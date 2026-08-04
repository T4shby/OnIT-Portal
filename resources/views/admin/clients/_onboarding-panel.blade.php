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

        <p class="portal-body-muted text-sm leading-relaxed">
            Work the open step.
            <strong class="text-white/80">Save client</strong> (left) stores IDs and flags only.
            Ticking a step on the right saves the checklist automatically — or use
            <strong class="text-white/80">Save checklist</strong> below.
        </p>

        @if(! empty($adminConsentUrl))
            <div class="mt-4 space-y-2">
                <a
                    href="{{ $adminConsentUrl }}"
                    target="_blank"
                    rel="noopener"
                    class="cta-btn inline-flex w-full items-center justify-center px-5 py-3 text-center text-sm"
                >Connect Microsoft tenant</a>
                <p class="portal-body-muted text-xs leading-relaxed">
                    Private browser · GDAP into <strong class="text-white/80">{{ $client->name }}</strong> · Accept once.
                    Portal saves tenant, licence, group and SuperOps Entra app IDs automatically.
                </p>
                @if(filled($client->entra_tenant_id))
                    <form method="POST" action="{{ route('admin.clients.bootstrap-entra', $client) }}">
                        @csrf
                        <button type="submit" class="cta-btn-ghost w-full px-4 py-2 text-xs">
                            Re-run Entra bootstrap (tenant already connected)
                        </button>
                    </form>
                @endif
            </div>
        @endif

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
            id="onboarding-checklist-form"
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
                <p class="portal-body-muted mt-2 text-xs leading-relaxed">
                    Tick “Mark this step complete” and it saves immediately.
                    <strong class="text-white/70">Save client</strong> on the left does not save these ticks.
                </p>
            </div>
        </form>
        <script>
            (function () {
                var form = document.getElementById('onboarding-checklist-form');
                if (!form) return;
                form.querySelectorAll('input[type="checkbox"][name^="checkpoints"]').forEach(function (box) {
                    box.addEventListener('change', function () {
                        if (typeof form.requestSubmit === 'function') {
                            form.requestSubmit();
                        } else {
                            form.submit();
                        }
                    });
                });
            })();
        </script>
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
