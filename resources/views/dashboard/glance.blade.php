@php
    $hero = $overview['hero'] ?? [];
    $cols = collect($overview['columns'] ?? [])->reject(fn ($c) => ($c['state'] ?? '') === 'hidden');
    $activity = $overview['activity'] ?? [];
    $monthCompare = $overview['month_compare'] ?? [];
    $orgWide = $overview['organisation_wide'] ?? false;
@endphp
<x-app-layout title="Dashboard" content-class="max-w-[96rem]">

    {{-- Hero --}}
    <section class="mb-8 sm:mb-10">
        <div class="orange-rule"></div>
        <div class="heading-stack mb-4">
            <h1 class="section-heading-white">Your IT</h1>
            <h1 class="section-heading-orange">at a glance</h1>
        </div>
        @if($user->client)
            <p class="portal-label mt-4">Organisation</p>
            <p class="portal-card-title mt-1">{{ $user->client->name }}</p>
        @endif
        <p class="portal-body-muted mt-3 text-sm">{{ $user->email }}</p>

        <div @class([
            'mt-6 border-l-[3px] pl-5 py-1',
            'border-emerald-400' => ($hero['status_tone'] ?? '') === 'ok',
            'border-amber-400' => ($hero['status_tone'] ?? '') === 'warn',
            'border-white/15' => ! in_array(($hero['status_tone'] ?? ''), ['ok', 'warn'], true),
        ])>
            <p class="font-condensed text-sm font-bold uppercase tracking-wide text-white">
                {{ $hero['status_line'] ?? 'Live service health for your organisation.' }}
            </p>
            <p class="portal-body-muted mt-2 text-xs">
                {{ $overview['period_label'] ?? '' }}
                · Snapshots refresh in the background
                @if($orgWide)
                    · <a href="{{ route('client-admin.dashboard') }}" class="text-onit hover:text-white">Full organisation detail</a>
                @endif
            </p>
        </div>
    </section>

    {{-- Month compare pipeline --}}
    @if(($monthCompare['status'] ?? '') === 'pipeline')
        <div class="mb-8 border border-amber-400/30 bg-amber-400/5 px-4 py-3">
            <p class="font-condensed text-xs font-bold uppercase tracking-wide text-amber-200">Comparison not set up</p>
            <p class="portal-body-muted mt-1 text-sm leading-relaxed">{{ $monthCompare['message'] }}</p>
        </div>
    @endif

    {{-- Your services plan strip --}}
    <section class="mb-8">
        <p class="portal-label mb-3">Your services</p>
        <div class="flex flex-wrap gap-2">
            @foreach($cols as $col)
                <span class="border border-onit-border bg-onit-surface px-3 py-1.5 font-condensed text-xs font-semibold uppercase tracking-wide text-white/80">
                    {{ $col['plan_label'] ?? $col['title'] }}
                </span>
            @endforeach
        </div>
    </section>

    {{-- Four service columns --}}
    <section class="mb-10">
        <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
            @foreach($cols as $col)
                @include('dashboard.partials._service-column', ['col' => $col])
            @endforeach
        </div>
    </section>

    {{-- Activity pipeline --}}
    <section class="mb-10">
        <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
            <div>
                <p class="portal-label">What we've done for you</p>
                <h2 class="portal-card-title mt-1">Activity</h2>
            </div>
        </div>
        <div class="border border-amber-400/30 bg-amber-400/5 px-5 py-5">
            <p class="font-condensed text-xs font-bold uppercase tracking-wide text-amber-200">Not set up yet — come back to this</p>
            <p class="portal-body-muted mt-2 text-sm leading-relaxed max-w-3xl">
                {{ $activity['message'] ?? 'Activity timeline requires a future event pipeline.' }}
            </p>
        </div>
    </section>

    {{-- Portals --}}
    @if($portalLinks->isNotEmpty())
        <section class="mb-10">
            <div class="mb-6">
                <div class="orange-rule !mb-4"></div>
                <div class="heading-stack">
                    <h2 class="section-heading-white !text-xl sm:!text-2xl">Your</h2>
                    <h2 class="section-heading-orange !text-xl sm:!text-2xl">Portals</h2>
                </div>
            </div>
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                @foreach($portalLinks as $link)
                    <x-service-card :link="$link" />
                @endforeach
            </div>
        </section>
    @endif

    <section class="portal-panel">
        <div class="flex flex-col gap-6 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <p class="portal-label mb-2">Support</p>
                <h3 class="portal-card-title">Need help?</h3>
                <p class="portal-body-muted mt-2">Raise a ticket in the portal or open SuperOps for the full conversation.</p>
            </div>
            <div class="flex flex-col sm:flex-row gap-3 shrink-0 w-full sm:w-auto">
                <a href="{{ route('support.index') }}" class="cta-btn w-full sm:w-auto justify-center">Support</a>
                <a href="{{ route('integrations.superops.launch') }}" class="cta-btn-ghost w-full sm:w-auto justify-center">Open SuperOps</a>
            </div>
        </div>
    </section>

</x-app-layout>
