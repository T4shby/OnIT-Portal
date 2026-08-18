@php
    $viewerIsTechnician = auth()->user()?->isTeamMember() ?? false;
    $s = $summary;
    $open = $s->openIncidents;
    $resolved = $s->resolvedIncidents;
    $agents = $s->agentsTotal;
    $unresponsive = $s->agentsUnresponsive;
    $isolated = $s->edrIsolatedAgents;
    $attentionOpen = ($open ?? 0) > 0;
    $attentionIsolated = ($isolated ?? 0) > 0;
    $attentionAgents = ($unresponsive ?? 0) > 0;
@endphp

<section class="mb-6">
    <div class="orange-rule"></div>
    <div class="heading-stack mb-4">
        <h1 class="section-heading-white">Huntress</h1>
        <h1 class="section-heading-orange">Security</h1>
    </div>
    <p class="portal-body-muted max-w-3xl">
        Security posture and cases for <strong class="text-white/80">{{ $client->name }}</strong>.
        @if($canViewAll ?? false)
            You can see every case for this organisation.
        @else
            You only see cases linked to you - not other people’s.
        @endif
    </p>
</section>

{{-- Flash alerts: app/admin layouts already render session success/error once. --}}

{{-- Toolbar --}}
<div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4 mb-8">
    <div class="flex flex-wrap items-center gap-3 text-sm portal-body-muted">
        @if($s->lastRefreshedAt)
            <span>Updated {{ $s->lastRefreshedAt->timezone('Europe/London')->format('d M Y H:i') }} UK</span>
        @elseif($list->lastRefreshedAt)
            <span>Cases {{ $list->lastRefreshedAt->timezone('Europe/London')->format('d M Y H:i') }} UK</span>
        @else
            <span>Not synced yet</span>
        @endif
        @if($s->refreshInProgress || $list->refreshInProgress)
            <span class="text-onit text-xs font-condensed uppercase tracking-wide">Refreshing…</span>
        @endif
        @if(filled($client->huntress_organization_id) && ($adminContext ?? false))
            <span class="text-xs text-white/40">Org ID {{ $client->huntress_organization_id }}</span>
        @endif
    </div>
    <div class="flex flex-wrap gap-3">
        @if(! empty($huntressConsoleUrl))
            <a href="{{ $huntressConsoleUrl }}" target="_blank" rel="noopener noreferrer"
               class="cta-btn-ghost text-sm px-6 py-3 inline-flex items-center gap-2">
                Open in Huntress
                <span aria-hidden="true" class="text-onit">↗</span>
            </a>
        @endif
        @if($canRefresh ?? false)
            <form method="POST" action="{{ $refreshRoute }}">
                @csrf
                <button type="submit" class="cta-btn-ghost text-sm px-6 py-3 w-full sm:w-auto">Refresh now</button>
            </form>
        @endif
    </div>
</div>

{{-- Metric tiles --}}
@if($s->hasData())
    <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-5 gap-3 sm:gap-4 mb-10">
        @include('security.huntress._metric-tile', [
            'label' => 'Active cases',
            'value' => $open,
            'tone' => $attentionOpen ? 'attention' : 'good',
            'hint' => 'Open incident reports',
            'icon' => '<svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z"/></svg>',
        ])
        @include('security.huntress._metric-tile', [
            'label' => 'Resolved',
            'value' => $resolved,
            'tone' => 'neutral',
            'hint' => 'Closed reports in cache',
            'icon' => '<svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>',
        ])
        @include('security.huntress._metric-tile', [
            'label' => 'Agents',
            'value' => $agents,
            'tone' => 'neutral',
            'hint' => 'Endpoints with Huntress',
            'icon' => '<svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 17.25v1.007a3 3 0 01-.879 2.122L7.5 21h9l-.621-.621A3 3 0 0115 18.257V17.25m6-12V15a2.25 2.25 0 01-2.25 2.25H5.25A2.25 2.25 0 013 15V5.25A2.25 2.25 0 015.25 3h13.5A2.25 2.25 0 0121 5.25z"/></svg>',
        ])
        @include('security.huntress._metric-tile', [
            'label' => 'Unresponsive',
            'value' => $unresponsive,
            'tone' => $attentionAgents ? 'attention' : 'neutral',
            'hint' => 'Agents not checking in',
            'icon' => '<svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>',
        ])
        @include('security.huntress._metric-tile', [
            'label' => 'Isolated',
            'value' => $isolated,
            'tone' => $attentionIsolated ? 'attention' : 'good',
            'hint' => 'Network isolation active',
            'icon' => '<svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z"/></svg>',
        ])
    </div>
@elseif($s->refreshInProgress || $list->refreshInProgress)
    <x-card class="mb-8">
        <p class="portal-body-muted text-sm">
            Syncing Huntress for this organisation. Metrics appear when the pull finishes - stay or refresh in a moment.
        </p>
    </x-card>
@else
    <x-card class="mb-8">
        <p class="portal-body-muted text-sm">
            @if($viewerIsTechnician)
                {{ $s->unavailableReason ?: $list->unavailableReason }}
            @else
                Security figures are not available yet. On IT is connecting Huntress for this organisation.
            @endif
        </p>
    </x-card>
@endif

{{-- Cases --}}
<div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-4 mb-4">
    <div>
        <h2 class="portal-label mb-1">Cases</h2>
        <p class="portal-body-muted text-sm">
            {{ number_format($list->activeCount) }} active
            · {{ number_format($list->resolvedCount) }} resolved
            @if(! ($canViewAll ?? false))
                <span class="text-white/40">(yours only)</span>
            @endif
        </p>
    </div>
    <div class="flex flex-wrap gap-2">
        @foreach(['all' => 'All', 'active' => 'Active', 'resolved' => 'Resolved'] as $key => $label)
            <a href="{{ $indexRoute }}?status={{ $key }}"
               class="px-3 py-1.5 text-xs border font-condensed uppercase tracking-wide {{ $filter === $key ? 'border-onit text-onit' : 'border-white/15 text-white/60 hover:text-white' }}">
                {{ $label }}
            </a>
        @endforeach
    </div>
</div>

@if(! $list->hasData())
    <x-card>
        <p class="portal-body-muted text-sm">
            @if($viewerIsTechnician)
                {{ $list->unavailableReason }}
            @else
                Security cases are not ready yet. On IT is syncing Huntress for this organisation.
            @endif
        </p>
    </x-card>
@elseif(count($list->incidents) === 0)
    <x-card>
        <p class="portal-body-muted text-sm">
            @if(! ($canViewAll ?? false))
                No security cases linked to you right now.
            @else
                No {{ $filter === 'all' ? '' : $filter.' ' }}cases for this organisation.
            @endif
        </p>
    </x-card>
@else
    <div class="space-y-2">
        @foreach($list->incidents as $case)
            @php
                $showUrl = $adminContext
                    ? route('admin.clients.security.huntress.show', [$client, $case->id])
                    : route('security.huntress.show', $case->id);
                $sev = strtolower((string) ($case->severity ?? ''));
                $sevClass = match (true) {
                    in_array($sev, ['critical', 'high'], true) => 'text-amber-300 border-amber-400/40',
                    in_array($sev, ['medium', 'moderate'], true) => 'text-onit border-onit/40',
                    default => 'text-white/60 border-white/15',
                };
            @endphp
            <a href="{{ $showUrl }}"
               class="block border border-white/10 bg-[#011926]/40 px-4 py-4 hover:border-onit/50 transition-colors group">
                <div class="flex gap-4">
                    <div class="shrink-0 mt-1 text-onit/80 group-hover:text-onit" aria-hidden="true">
                        @if($case->isActive)
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z"/></svg>
                        @else
                            <svg class="w-5 h-5 text-emerald-400/80" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        @endif
                    </div>
                    <div class="min-w-0 flex-1 flex flex-col sm:flex-row sm:items-start sm:justify-between gap-2">
                        <div class="min-w-0">
                            <p class="text-white font-medium leading-snug group-hover:text-white">{{ $case->subject }}</p>
                            @if($case->summary)
                                <p class="portal-body-muted text-sm mt-2 line-clamp-2 leading-relaxed">{{ \Illuminate\Support\Str::limit($case->summary, 200) }}</p>
                            @endif
                            @if($case->platform || $case->indicatorTypes !== [])
                                <p class="text-xs text-white/40 mt-2">
                                    @if($case->platform)
                                        {{ ucfirst($case->platform) }}
                                    @endif
                                    @if($case->platform && $case->indicatorTypes !== [])
                                        ·
                                    @endif
                                    @if($case->indicatorTypes !== [])
                                        {{ implode(', ', array_slice($case->indicatorTypes, 0, 4)) }}
                                    @endif
                                </p>
                            @endif
                        </div>
                        <div class="shrink-0 flex flex-wrap gap-2 sm:flex-col sm:items-end">
                            <span class="text-xs font-condensed uppercase tracking-wide {{ $case->isActive ? 'text-amber-300' : 'text-emerald-400' }}">
                                {{ $case->statusLabel() }}
                            </span>
                            @if($case->severity)
                                <span class="text-xs border px-2 py-0.5 font-condensed uppercase tracking-wide {{ $sevClass }}">
                                    {{ ucfirst($case->severity) }}
                                </span>
                            @endif
                            @if($case->sentAt)
                                <span class="text-xs text-white/40">{{ $case->sentAt->timezone('Europe/London')->format('d M Y') }}</span>
                            @endif
                        </div>
                    </div>
                </div>
            </a>
        @endforeach
    </div>
@endif
