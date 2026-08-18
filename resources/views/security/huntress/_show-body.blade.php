<section class="mb-6">
    <div class="orange-rule"></div>
    <div class="heading-stack mb-4">
        <h1 class="section-heading-white">Security</h1>
        <h1 class="section-heading-orange">Case</h1>
    </div>
    <p class="portal-body-muted max-w-3xl">
        <a href="{{ $indexRoute }}" class="text-onit hover:text-white">&larr; All cases</a>
        · {{ $client->name }}
    </p>
</section>

<x-card class="mb-6">
    <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4 mb-6">
        <div class="min-w-0">
            <p class="portal-label mb-2">Subject</p>
            <h2 class="text-xl font-condensed font-bold text-white leading-snug">{{ $case->subject }}</h2>
        </div>
        <div class="shrink-0 flex flex-wrap gap-2">
            <span class="border px-3 py-1 text-xs font-condensed uppercase tracking-wide {{ $case->isActive ? 'border-amber-400/50 text-amber-300' : 'border-emerald-400/40 text-emerald-400' }}">
                {{ $case->statusLabel() }}
            </span>
            @if($case->severity)
                <span class="border border-white/15 px-3 py-1 text-xs text-white/70">{{ ucfirst($case->severity) }}</span>
            @endif
            @if($case->platform)
                <span class="border border-white/15 px-3 py-1 text-xs text-white/70">{{ ucfirst($case->platform) }}</span>
            @endif
        </div>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6 text-sm">
        <div>
            <p class="portal-body-muted text-xs mb-1">Opened</p>
            <p class="text-white/90">
                {{ $case->sentAt ? $case->sentAt->timezone('Europe/London')->format('d M Y H:i').' UK' : '-' }}
            </p>
        </div>
        <div>
            <p class="portal-body-muted text-xs mb-1">Closed</p>
            <p class="text-white/90">
                {{ $case->closedAt ? $case->closedAt->timezone('Europe/London')->format('d M Y H:i').' UK' : '-' }}
            </p>
        </div>
        <div>
            <p class="portal-body-muted text-xs mb-1">Huntress status</p>
            <p class="text-white/90">{{ ucfirst($case->status) }}</p>
        </div>
    </div>

    @if($case->summary)
        <div class="mb-6">
            <p class="portal-label mb-3">Summary</p>
            <p class="text-white/85 text-sm leading-relaxed whitespace-pre-wrap">{{ $case->summary }}</p>
        </div>
    @endif

    @if($case->body && $case->body !== $case->summary)
        <div class="mb-6">
            <p class="portal-label mb-3">Detail</p>
            <p class="text-white/75 text-sm leading-relaxed whitespace-pre-wrap">{{ $case->body }}</p>
        </div>
    @endif

    @if($case->indicatorTypes !== [])
        <div class="mb-6">
            <p class="portal-label mb-3">Indicators</p>
            <div class="flex flex-wrap gap-2">
                @foreach($case->indicatorTypes as $type)
                    <span class="text-xs border border-white/15 px-2 py-1 text-white/70">{{ $type }}</span>
                @endforeach
            </div>
        </div>
    @endif

    @if($case->remediations !== [])
        <div>
            <p class="portal-label mb-3">Remediations</p>
            <ul class="space-y-2 text-sm">
                @foreach($case->remediations as $rem)
                    <li class="border border-white/10 px-3 py-2 text-white/80 flex flex-wrap justify-between gap-2">
                        <span>{{ $rem['action'] ?? 'Remediation' }}</span>
                        @if(filled($rem['status'] ?? null))
                            <span class="text-white/50 text-xs self-center">{{ $rem['status'] }}</span>
                        @endif
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
</x-card>
