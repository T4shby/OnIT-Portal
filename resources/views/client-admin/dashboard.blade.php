<x-app-layout title="Client Admin">

    <section class="mb-8 sm:mb-10">
        <div class="orange-rule"></div>
        <div class="heading-stack mb-4">
            <h1 class="section-heading-white">Organisation</h1>
            <h1 class="section-heading-orange">Overview</h1>
        </div>
        <p class="portal-body-muted max-w-2xl">
            High-level summary for <strong class="text-white/80">{{ $client->name }}</strong>. Open SuperOps for full tickets, assets, and detail.
        </p>
    </section>

    @if($summary->unavailableReason && ! $summary->hasData())
        <x-alert type="warning" class="mb-6">{{ $summary->unavailableReason }}</x-alert>
    @elseif($summary->isStale && $summary->lastRefreshedAt)
        <x-alert type="warning" class="mb-6">
            SuperOps is currently unavailable or data is stale. Showing information last refreshed at
            {{ $summary->lastRefreshedAt->timezone('Europe/London')->format('d M Y H:i') }} UK.
        </x-alert>
    @endif

    @if($summary->refreshInProgress)
        <x-alert type="info" class="mb-6">Refresh in progress. Counts will update shortly.</x-alert>
    @endif

    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-8">
        @if($summary->lastRefreshedAt)
            <p class="portal-body-muted text-xs">
                Last refreshed {{ $summary->lastRefreshedAt->timezone('Europe/London')->format('d M Y H:i') }} UK
            </p>
        @endif
        <form method="POST" action="{{ route('client-admin.refresh') }}">
            @csrf
            <button type="submit" class="cta-btn-ghost text-sm px-6 py-3 w-full sm:w-auto">Refresh now</button>
        </form>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-8">
        <x-card>
            <p class="portal-label mb-2">Managed assets</p>
            <p class="text-4xl font-condensed font-bold text-onit">
                @if($summary->assetsTotal === null)
                    —
                @else
                    {{ number_format($summary->assetsTotal) }}
                @endif
            </p>
            <p class="portal-body-muted text-sm mt-2">Devices in SuperOps</p>
            @if($summary->assetsTotal !== null)
                <a href="{{ route('integrations.superops.launch') }}"
                   class="cta-btn-ghost text-sm px-6 py-3 mt-6 inline-flex">
                    Open SuperOps
                </a>
            @endif
        </x-card>

        <x-card>
            <p class="portal-label mb-2">Open tickets</p>
            <p class="text-4xl font-condensed font-bold text-onit">
                @if($summary->openTicketsTotal === null)
                    —
                @else
                    {{ number_format($summary->openTicketsTotal) }}
                @endif
            </p>
            <p class="portal-body-muted text-sm mt-2">Currently open in SuperOps</p>
            @if($summary->openTicketsTotal !== null)
                <a href="{{ route('integrations.superops.launch') }}"
                   class="cta-btn-ghost text-sm px-6 py-3 mt-6 inline-flex">
                    Open SuperOps
                </a>
            @endif
        </x-card>
    </div>

    <div x-data="{ range: '7' }" class="space-y-6">
        <div class="flex flex-wrap gap-2">
            @foreach(['7' => '7 days', '14' => '14 days', '30' => '30 days', 'all' => 'All time'] as $key => $label)
                <button type="button"
                        @click="range = '{{ $key }}'"
                        :class="range === '{{ $key }}' ? 'border-onit text-onit' : 'border-white/15 text-white/60'"
                        class="px-3 py-1.5 text-xs border font-condensed uppercase tracking-wide">
                    {{ $label }}
                </button>
            @endforeach
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <x-card>
                <p class="portal-label mb-2">Tickets logged</p>
                @foreach(['7', '14', '30', 'all'] as $key)
                    <p x-show="range === '{{ $key }}'" class="text-4xl font-condensed font-bold text-white">
                        @php $count = $summary->ticketsCreated[$key] ?? null; @endphp
                        {{ $count === null ? '—' : number_format($count) }}
                    </p>
                @endforeach
                <a href="{{ route('integrations.superops.launch') }}"
                   class="cta-btn-ghost text-sm px-6 py-3 mt-6 inline-flex">
                    Open SuperOps
                </a>
            </x-card>

            <x-card>
                <p class="portal-label mb-2">Tickets closed</p>
                @foreach(['7', '14', '30', 'all'] as $key)
                    <p x-show="range === '{{ $key }}'" class="text-4xl font-condensed font-bold text-white">
                        @php $count = $summary->ticketsClosed[$key] ?? null; @endphp
                        {{ $count === null ? '—' : number_format($count) }}
                    </p>
                @endforeach
                <a href="{{ route('integrations.superops.launch') }}"
                   class="cta-btn-ghost text-sm px-6 py-3 mt-6 inline-flex">
                    Open SuperOps
                </a>
            </x-card>
        </div>
    </div>

</x-app-layout>
