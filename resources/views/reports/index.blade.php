@php
    $cols = collect($overview['columns'] ?? [])->reject(fn ($c) => ($c['state'] ?? '') === 'hidden');
    $activity = $overview['activity'] ?? [];
    $monthCompare = $overview['month_compare'] ?? [];
@endphp
<x-app-layout title="Reports" content-class="max-w-[96rem]">

    <section class="mb-8 sm:mb-10">
        <div class="orange-rule"></div>
        <div class="heading-stack mb-4">
            <h1 class="section-heading-white">Monthly</h1>
            <h1 class="section-heading-orange">service review</h1>
        </div>
        <p class="portal-label mt-4">Prepared for</p>
        <p class="portal-card-title mt-1">{{ $client->name }}</p>
        <p class="portal-body-muted mt-3 max-w-2xl text-sm leading-relaxed">
            Value overview for {{ $overview['period_label'] ?? now()->format('F Y') }}.
            Live metrics come from sold products; anything marked
            <span class="text-amber-200">Not set up</span> still needs a portal pipeline or Microsoft permission — we do not invent figures.
        </p>
    </section>

    @if(($monthCompare['status'] ?? '') === 'pipeline')
        <div class="mb-8 border border-amber-400/30 bg-amber-400/5 px-4 py-3">
            <p class="font-condensed text-xs font-bold uppercase tracking-wide text-amber-200">History not set up</p>
            <p class="portal-body-muted mt-1 text-sm leading-relaxed">{{ $monthCompare['message'] }}</p>
        </div>
    @endif

    {{-- Services rail + metrics rows --}}
    <section class="mb-10 grid grid-cols-1 gap-6 lg:grid-cols-12">
        <aside class="lg:col-span-3 border border-onit-border bg-onit-surface p-5">
            <p class="portal-label mb-4">Your services</p>
            <ul class="space-y-3">
                @foreach($cols as $col)
                    <li class="border-b border-white/5 pb-3 last:border-0">
                        <p class="font-condensed text-sm font-bold uppercase text-white">{{ $col['plan_label'] ?? $col['title'] }}</p>
                        <p class="mt-1 text-xs text-white/45">{{ $col['source'] }} · {{ $col['status_label'] }}</p>
                    </li>
                @endforeach
            </ul>
            <a href="{{ route('dashboard') }}" class="mt-6 inline-block text-sm text-onit hover:text-white font-condensed font-semibold uppercase tracking-wide">
                Dashboard →
            </a>
        </aside>

        <div class="lg:col-span-9 space-y-4">
            @foreach($cols as $col)
                <article class="border border-onit-border bg-onit-surface">
                    <div class="flex flex-col gap-2 border-b border-white/10 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <p class="font-condensed text-xs font-bold uppercase tracking-wide text-onit">{{ $col['source'] }}</p>
                            <h2 class="font-condensed text-xl font-bold uppercase text-white">{{ $col['title'] }}</h2>
                        </div>
                        <p class="font-condensed text-xs font-bold uppercase tracking-wide text-white/60">{{ $col['status_label'] }}</p>
                    </div>
                    <div class="px-5 py-4">
                        @if(! empty($col['message']) && ($col['state'] ?? '') !== 'live')
                            <p class="mb-4 text-sm text-amber-100/90 leading-relaxed">{{ $col['message'] }}</p>
                        @endif
                        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                            @foreach(($col['metrics'] ?? []) as $metric)
                                <div class="border border-white/5 bg-onit-ink/40 px-3 py-3">
                                    <p class="font-condensed text-[0.65rem] font-bold uppercase tracking-wide text-white/45">{{ $metric['label'] }}</p>
                                    <p @class([
                                        'mt-1 font-condensed text-lg font-bold uppercase',
                                        'text-amber-300' => ($metric['kind'] ?? '') === 'pipeline',
                                        'text-white/40' => ($metric['kind'] ?? '') === 'empty',
                                        'text-white' => ($metric['kind'] ?? 'ok') === 'ok',
                                    ])>{{ $metric['value'] }}</p>
                                    @if(! empty($metric['hint']))
                                        <p class="mt-1 text-[0.7rem] leading-snug text-white/40">{{ $metric['hint'] }}</p>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                        @if(! empty($col['href']) && ($col['state'] ?? '') !== 'not_sold')
                            <a href="{{ $col['href'] }}" class="mt-4 inline-block text-sm text-onit hover:text-white font-condensed font-semibold uppercase tracking-wide">
                                Details →
                            </a>
                        @endif
                    </div>
                </article>
            @endforeach
        </div>
    </section>

    <section class="mb-10">
        <p class="portal-label">Questions about your service or this report?</p>
        <div class="mt-4 flex flex-col sm:flex-row gap-3">
            <a href="{{ route('support.create') }}" class="cta-btn w-full sm:w-auto justify-center">Talk to an expert</a>
            <a href="{{ route('integrations.superops.launch') }}" class="cta-btn-ghost w-full sm:w-auto justify-center">Open SuperOps</a>
        </div>
    </section>

    <section>
        <div class="border border-amber-400/30 bg-amber-400/5 px-5 py-5">
            <p class="font-condensed text-xs font-bold uppercase tracking-wide text-amber-200">Activity narrative not set up</p>
            <p class="portal-body-muted mt-2 text-sm leading-relaxed max-w-3xl">
                {{ $activity['message'] ?? 'Activity list requires a future pipeline.' }}
            </p>
        </div>
    </section>

</x-app-layout>
