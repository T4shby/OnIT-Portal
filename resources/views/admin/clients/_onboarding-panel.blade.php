@php
    $hasManualCheckboxes = collect($onboardingSteps)->contains(
        fn (array $step) => $step['manual'] && ! $step['complete'] && ! ($step['blocked'] && ! $step['complete'])
    );
@endphp

<div class="onboarding-panel lg:sticky lg:top-8 lg:max-h-[calc(100vh-4rem)] lg:overflow-y-auto">
    <div class="onboarding-panel__header">
        <div class="orange-rule"></div>
        <div class="heading-stack mb-3">
            <h2 class="section-heading-white !text-[1.2rem] sm:!text-[1.4rem]">Client setup</h2>
            <h2 class="section-heading-orange !text-[1.2rem] sm:!text-[1.4rem]">Guide</h2>
        </div>

        <details class="onboarding-panel__help">
            <summary>How this guide works</summary>
            <div class="onboarding-panel__help-body">
                <p>
                    Open one step at a time. Step <strong class="text-white/80">00</strong> is mail domain + DNS
                    (Save client for main domain, then Mail domains on the left for Import / extras).
                    SCIM (07) and Client SSO (08) action forms live inside those steps.
                </p>
                <p>
                    <strong class="text-white/80">Save client</strong> stores name, main domain, products, and Entra IDs.
                    Checklist ticks save from this panel.
                </p>
            </div>
        </details>

        @if($mailDnsStillRed ?? false)
            <div class="mt-3 border border-amber-400/40 bg-amber-400/10 px-3 py-2 text-xs text-amber-50">
                Mail DNS still has <strong class="text-white">red</strong> checks.
                Fix them in Mail domains (left), or hand off with the warning.
            </div>
        @endif

        @if(! empty($adminConsentUrl))
            <div class="mt-4 space-y-2">
                @if(! filled($client->entra_tenant_id))
                    <a
                        href="{{ $adminConsentUrl }}"
                        target="_blank"
                        rel="noopener"
                        class="cta-btn inline-flex w-full items-center justify-center px-4 py-2.5 text-center text-sm"
                    >Connect Microsoft tenant</a>
                    <details class="onboarding-panel__help">
                        <summary>About Connect</summary>
                        <div class="onboarding-panel__help-body">
                            <p>
                                Only button that opens Microsoft Accept (private browser · GDAP into
                                <strong class="text-white/80">{{ $client->name }}</strong>).
                                After Accept the portal waits for Graph and creates group + SuperOps app IDs automatically.
                            </p>
                        </div>
                    </details>
                @else
                    @php($bootstrapInFlight = \App\Jobs\BootstrapClientEntraJob::isInFlight($client->id))
                    <details class="onboarding-panel__help" @if($bootstrapInFlight) open @endif>
                        <summary>Graph tools</summary>
                        <div class="onboarding-panel__help-body space-y-3">
                            <form method="POST" action="{{ route('admin.clients.bootstrap-entra', $client) }}">
                                @csrf
                                <button type="submit" class="cta-btn w-full px-4 py-2.5 text-center text-sm" @disabled($bootstrapInFlight)>
                                    @if($bootstrapInFlight) Graph setup running… @else Retry Graph setup @endif
                                </button>
                            </form>
                            @if($bootstrapInFlight)
                                <p class="text-xs leading-relaxed text-amber-100">
                                    Graph setup is running in the background. Refresh in about a minute.
                                </p>
                            @else
                                <p>
                                    Tenant ID is saved. Use Retry only if group or SuperOps app IDs are still empty
                                    (Azure lag after Accept - not a second Microsoft login).
                                </p>
                            @endif
                            <a
                                href="{{ $adminConsentUrl }}"
                                target="_blank"
                                rel="noopener"
                                class="cta-btn-ghost inline-flex w-full items-center justify-center px-4 py-2.5 text-center text-sm"
                            >Re-consent Graph permissions</a>
                            <p>
                                Private browser · GDAP into <strong class="text-white/80">{{ $client->name }}</strong>.
                                Use after On IT adds Application permissions. Does not redo SCIM or Client SSO.
                            </p>
                        </div>
                    </details>
                @endif
            </div>
        @endif

        <div class="onboarding-panel__progress">
            <div class="flex items-end justify-between gap-4">
                <div>
                    <p class="portal-label mb-1">Progress</p>
                    <p class="font-condensed text-base font-bold uppercase tracking-wide text-white">
                        {{ $onboardingProgress['complete'] }} of {{ $onboardingProgress['total'] }} steps
                    </p>
                </div>
                <p class="font-condensed text-xl font-extrabold text-onit">{{ $onboardingProgress['percent'] }}%</p>
            </div>
            <div class="onboarding-panel__progress-bar">
                <div class="onboarding-panel__progress-fill" style="width: {{ $onboardingProgress['percent'] }}%"></div>
            </div>
        </div>
    </div>

    {{-- Checklist form sits empty here so step action forms (SCIM/SSO) are not nested. Checkboxes use form="…". --}}
    <form
        id="onboarding-checklist-form"
        method="POST"
        action="{{ route('admin.clients.onboarding.update', $client) }}"
        class="hidden"
        aria-hidden="true"
    >
        @csrf
        @method('PUT')
    </form>

    <div class="onboarding-panel__body">
        @include('admin.clients._onboarding-steps', [
            'client' => $client,
            'onboardingSteps' => $onboardingSteps,
            'adminConsentUrl' => $adminConsentUrl,
            'showCheckboxes' => $hasManualCheckboxes,
        ])
    </div>

    @if($hasManualCheckboxes)
        <div class="onboarding-panel__actions">
            <button type="submit" form="onboarding-checklist-form" class="cta-btn text-sm">Save checklist</button>
            <p class="portal-body-muted mt-2 text-xs leading-relaxed">
                Tick “Mark this step complete” and it saves immediately.
                SCIM / Client SSO action buttons are inside steps 07 and 08.
            </p>
        </div>
        <script>
            (function () {
                var form = document.getElementById('onboarding-checklist-form');
                if (!form) return;
                document.querySelectorAll('input[type="checkbox"][name^="checkpoints"][form="onboarding-checklist-form"]').forEach(function (box) {
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
    @endif
</div>
