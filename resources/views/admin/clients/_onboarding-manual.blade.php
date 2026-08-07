@if(!empty($guide))
    @php
        $openRecovery = $openRecovery ?? false;
    @endphp
    <div class="onboarding-manual">
        @if(!empty($guide['automated']))
            <div class="onboarding-manual__callout border border-emerald-500/30 bg-emerald-500/5">
                <h3 class="onboarding-manual__block-title text-emerald-300/90">Already done automatically</h3>
                <ul class="onboarding-manual__notes">
                    @foreach($guide['automated'] as $line)
                        <li>{!! \App\Support\OnboardingStepFormatter::rich($line) !!}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if(!empty($guide['warnings']))
            <div class="onboarding-manual__callout border border-amber-400/40 bg-amber-500/10">
                <h3 class="onboarding-manual__block-title text-amber-200">Do not ignore</h3>
                <ul class="onboarding-manual__notes text-amber-50/90">
                    @foreach($guide['warnings'] as $line)
                        <li>{!! \App\Support\OnboardingStepFormatter::rich($line) !!}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if(!empty($guide['notes']))
            <div class="onboarding-manual__callout">
                <h3 class="onboarding-manual__block-title">What is left</h3>
                <ul class="onboarding-manual__notes">
                    @foreach($guide['notes'] as $note)
                        <li>{!! \App\Support\OnboardingStepFormatter::rich($note) !!}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if(!empty($guide['prerequisites']))
            <div class="onboarding-manual__prerequisites">
                <h3 class="onboarding-manual__block-title onboarding-manual__block-title--muted">Before you start</h3>
                <ul class="onboarding-manual__prereq-list">
                    @foreach($guide['prerequisites'] as $prerequisite)
                        <li>{!! \App\Support\OnboardingStepFormatter::rich($prerequisite) !!}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @foreach($guide['sections'] as $section)
            <div class="onboarding-manual__section">
                <div class="onboarding-manual__section-header">
                    <h4 class="onboarding-manual__section-title">{{ $section['title'] }}</h4>
                </div>

                @if(!empty($section['where']))
                    <div class="onboarding-manual__where">
                        <span class="onboarding-manual__where-label">Where</span>
                        <span class="onboarding-manual__where-value">{{ $section['where'] }}</span>
                    </div>
                @endif

                <ol class="onboarding-manual__steps">
                    @foreach($section['steps'] as $step)
                        <li class="onboarding-manual__step">{!! \App\Support\OnboardingStepFormatter::rich($step) !!}</li>
                    @endforeach
                </ol>

                @if(!empty($section['notes']))
                    <ul class="onboarding-manual__prereq-list mt-3">
                        @foreach($section['notes'] as $note)
                            <li>{!! \App\Support\OnboardingStepFormatter::rich($note) !!}</li>
                        @endforeach
                    </ul>
                @endif
            </div>
        @endforeach

        @if(!empty($guide['recovery']))
            <details class="onboarding-manual__recovery mt-4 border border-white/10 bg-white/[0.02] px-4 py-3" @if($openRecovery) open @endif>
                <summary class="cursor-pointer select-none text-sm font-condensed uppercase tracking-wide text-onit">
                    Only if something failed — open recovery steps
                </summary>
                <div class="mt-4 space-y-4">
                    @foreach($guide['recovery'] as $section)
                        <div class="onboarding-manual__section">
                            <div class="onboarding-manual__section-header">
                                <h4 class="onboarding-manual__section-title">{{ $section['title'] }}</h4>
                            </div>
                            @if(!empty($section['where']))
                                <div class="onboarding-manual__where">
                                    <span class="onboarding-manual__where-label">Where</span>
                                    <span class="onboarding-manual__where-value">{{ $section['where'] }}</span>
                                </div>
                            @endif
                            <ol class="onboarding-manual__steps">
                                @foreach($section['steps'] as $step)
                                    <li class="onboarding-manual__step">{!! \App\Support\OnboardingStepFormatter::rich($step) !!}</li>
                                @endforeach
                            </ol>
                        </div>
                    @endforeach
                </div>
            </details>
        @endif

        @if(!empty($guide['verify']))
            <div class="onboarding-manual__verify">
                <h3 class="onboarding-manual__block-title">Done when</h3>
                <ol class="onboarding-manual__steps onboarding-manual__steps--verify">
                    @foreach($guide['verify'] as $check)
                        <li class="onboarding-manual__step">{!! \App\Support\OnboardingStepFormatter::rich($check) !!}</li>
                    @endforeach
                </ol>
            </div>
        @endif
    </div>
@elseif(!empty($instructions))
    <ul class="support-list">
        @foreach($instructions as $instruction)
            <li>{{ $instruction }}</li>
        @endforeach
    </ul>
@endif
