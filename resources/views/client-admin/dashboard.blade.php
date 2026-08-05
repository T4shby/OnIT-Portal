<x-app-layout title="Client Admin" content-class="max-w-[96rem]">

    @php
        $refreshing = $summary->refreshInProgress
            || $m365Insights->refreshInProgress
            || $huntressSummary->refreshInProgress
            || $dropsuiteSummary->refreshInProgress;
    @endphp

    <section class="mb-6">
        <div class="orange-rule"></div>
        <div class="heading-stack mb-4">
            <h1 class="section-heading-white">Organisation</h1>
            <h1 class="section-heading-orange">Overview</h1>
        </div>
        <p class="portal-body-muted max-w-3xl">
            Are your systems healthy? Are issues being dealt with? What value are you getting from On IT?
            Summary for <strong class="text-white/80">{{ $client->name }}</strong>.
        </p>
    </section>

    @if($summary->unavailableReason && ! $summary->hasData())
        <x-alert type="warning" class="mb-6">{{ $summary->unavailableReason }}</x-alert>
    @elseif($summary->isStale && $summary->lastRefreshedAt)
        <x-alert type="warning" class="mb-6">
            Some data is stale. Last SuperOps refresh
            {{ $summary->lastRefreshedAt->timezone('Europe/London')->format('d M Y H:i') }} UK.
        </x-alert>
    @endif

    @include('client-admin._dashboard-live-root')

    <x-live-fragment-poll
        :url="route('client-admin.live')"
        target-id="client-admin-live"
        :seconds="5"
        :active="$refreshing"
    />

</x-app-layout>
