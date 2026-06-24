@if(!empty($guide))
    <div class="onboarding-manual">
        @if(!empty($guide['notes']))
            <div class="onboarding-manual__callout">
                <p class="onboarding-manual__label">Important</p>
                <ul class="onboarding-manual__notes">
                    @foreach($guide['notes'] as $note)
                        <li>{{ $note }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if(!empty($guide['prerequisites']))
            <div class="onboarding-manual__prerequisites">
                <p class="onboarding-manual__label">Before you start</p>
                <p class="onboarding-manual__hint portal-body-muted text-sm">Complete these first — they are not numbered steps.</p>
                <ul class="onboarding-manual__prereq-list">
                    @foreach($guide['prerequisites'] as $prerequisite)
                        <li>{{ $prerequisite }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @foreach($guide['sections'] as $section)
            <div class="onboarding-manual__section">
                <p class="onboarding-manual__label">{{ $section['title'] }}</p>
                @if(!empty($section['where']))
                    <p class="onboarding-manual__where portal-body-muted text-sm">
                        Where: <span class="text-white/80">{{ $section['where'] }}</span>
                    </p>
                @endif
                <ol class="onboarding-manual__steps">
                    @foreach($section['steps'] as $step)
                        <li class="onboarding-manual__step">{{ $step }}</li>
                    @endforeach
                </ol>
            </div>
        @endforeach

        @if(!empty($guide['verify']))
            <div class="onboarding-manual__verify">
                <p class="onboarding-manual__label">Check your work</p>
                <ol class="onboarding-manual__steps onboarding-manual__steps--verify">
                    @foreach($guide['verify'] as $check)
                        <li class="onboarding-manual__step">{{ $check }}</li>
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
