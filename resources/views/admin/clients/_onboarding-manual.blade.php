@if(!empty($guide))
    <div class="onboarding-manual">
        @if(!empty($guide['notes']))
            <div class="onboarding-manual__callout">
                <h3 class="onboarding-manual__block-title">Remember</h3>
                <ul class="onboarding-manual__notes">
                    @foreach($guide['notes'] as $note)
                        <li>{!! \App\Support\OnboardingStepFormatter::rich($note) !!}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if(!empty($guide['prerequisites']))
            <div class="onboarding-manual__prerequisites">
                <h3 class="onboarding-manual__block-title onboarding-manual__block-title--muted">Start here</h3>
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
