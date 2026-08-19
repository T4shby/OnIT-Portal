@php
    $orgWide = $organisationWide ?? true;
    $pageTitle = 'Support & Devices';
@endphp
<x-app-layout :title="$pageTitle" content-class="max-w-[96rem]">

@php
    $refreshing = $summary->refreshInProgress;
    $viewerIsTechnician = auth()->user()?->isTeamMember() ?? false;
    $superOpsAgeMinutes = $summary->lastRefreshedAt
        ? (int) round($summary->lastRefreshedAt->diffInMinutes(now()))
        : null;
    $freshMinutes = (float) config('services.superops.dashboard_cache_minutes', 5);
    $refreshAfterMinutes = (float) config('services.superops.dashboard_refresh_after_minutes', 2.5);
@endphp

<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
@include('partials.org-theme-styles')

<div class="org" style="padding-bottom:1rem">
    <div style="border-bottom:1px solid #1F2933;padding-bottom:1.5rem;margin-bottom:1.25rem">
        <div style="display:flex;flex-wrap:wrap;gap:1rem;justify-content:space-between;align-items:flex-start">
            <div style="min-width:0;flex:1 1 16rem">
                <div class="org-label" style="margin-bottom:10px">Services</div>
                <h1 style="margin:0;font-size:clamp(1.5rem,3vw,2rem);font-weight:700;letter-spacing:-0.02em;line-height:1.15">
                    Support &amp; Devices
                </h1>
                <p class="org-muted" style="margin:10px 0 0;font-size:13.5px;line-height:1.5;max-width:42rem">
                    @if($orgWide)
                        Tickets, SLA, and managed devices from SuperOps for
                        <span style="color:rgba(255,255,255,.9)">{{ $client->name }}</span>.
                    @else
                        Your SuperOps tickets at
                        <span style="color:rgba(255,255,255,.9)">{{ $client->name }}</span>
                        - you only see items linked to you.
                    @endif
                </p>
            </div>
        </div>
    </div>

    @if($summary->unavailableReason && ! $summary->hasData())
        @php
            $products = app(\App\Services\Portal\ClientProductService::class);
            $showAmBanner = $products->isEntitled($client, 'superops')
                && $products->needsAccountManagerHelp($client, 'superops')
                && ($organisationWide ?? false)
                && ! $viewerIsTechnician;
        @endphp
        @if($viewerIsTechnician)
            <x-alert type="warning" class="mb-6">
                {{ $summary->unavailableReason }}
            </x-alert>
        @elseif($showAmBanner)
            <x-alert type="info" class="mb-6">
                Please contact your account manager to get this sorted.
            </x-alert>
        @elseif(($organisationWide ?? false) && $products->isLive($client, 'superops') === false && $products->isEntitled($client, 'superops') && $client->hasSuperOpsLinked())
            <x-alert type="info" class="mb-6">
                Support figures are still loading. Try again shortly, or use Refresh now.
            </x-alert>
        @endif
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
                These figures refresh through the day. Shown as of
                {{ $summary->lastRefreshedAt->timezone('Europe/London')->format('d M Y H:i') }} UK.
                @if($orgWide)
                    Use Refresh now if you need the latest pull.
                @endif
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
</div>
</x-app-layout>
