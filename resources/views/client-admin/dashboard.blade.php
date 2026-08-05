<x-app-layout title="Client Admin" content-class="max-w-[96rem]">

    @php
        $refreshing = $summary->refreshInProgress
            || $m365Insights->refreshInProgress
            || $huntressSummary->refreshInProgress
            || $dropsuiteSummary->refreshInProgress;
        $viewerIsTechnician = auth()->user()?->isTeamMember() ?? false;
        $superOpsAgeMinutes = $summary->lastRefreshedAt
            ? (int) round($summary->lastRefreshedAt->diffInMinutes(now()))
            : null;
        $freshMinutes = (float) config('services.superops.dashboard_cache_minutes', 5);
        $refreshAfterMinutes = (float) config('services.superops.dashboard_refresh_after_minutes', 2.5);
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
        <x-alert type="warning" class="mb-6">
            @if($viewerIsTechnician)
                {{ $summary->unavailableReason }}
            @else
                Organisation metrics are not ready yet. On IT is connecting the systems that power this overview.
            @endif
        </x-alert>
    @elseif($summary->isStale && $summary->lastRefreshedAt)
        @if($viewerIsTechnician)
            <x-alert type="warning" class="mb-6">
                SuperOps snapshot past {{ $freshMinutes }}m client window
                ({{ $superOpsAgeMinutes }}m ago · last success
                {{ $summary->lastRefreshedAt->timezone('Europe/London')->format('d M Y H:i') }} UK).
                Prewarm requeues after {{ $refreshAfterMinutes }}m. Check Admin → Integration refresh health if this climbs.
            </x-alert>
        @else
            <x-alert type="info" class="mb-6">
                These figures refresh often through the day. Overview includes data as of
                {{ $summary->lastRefreshedAt->timezone('Europe/London')->format('d M Y H:i') }} UK.
                Use Refresh now if you need the latest pull.
            </x-alert>
        @endif
    @endif

    @include('client-admin._dashboard-live-root')

    <x-live-fragment-poll
        :url="route('client-admin.live')"
        target-id="client-admin-live"
        :seconds="5"
        :active="$refreshing"
    />

</x-app-layout>
