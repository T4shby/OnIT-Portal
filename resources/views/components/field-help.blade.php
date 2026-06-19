@props(['title' => 'Help', 'steps' => []])

<details class="field-help group">
    <summary class="field-help__trigger" aria-label="Show help for {{ $title }}">
        <span class="field-help__icon">?</span>
        <span class="field-help__label">Help</span>
    </summary>
    <div class="field-help__panel">
        <p class="field-help__title">{{ $title }}</p>
        <ol class="field-help__steps">
            @foreach($steps as $step)
                <li>{{ $step }}</li>
            @endforeach
        </ol>
    </div>
</details>
