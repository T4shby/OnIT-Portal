@php
    $orgWide = $organisationWide ?? true;
    $pageTitle = $orgWide ? 'Organisation Overview' : 'My Systems';
@endphp
<x-app-layout :title="$pageTitle" content-class="max-w-[96rem]">

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

<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
    .org { font-family: 'Poppins', system-ui, sans-serif; color: #fff; }
    .org-label { font-size: 11px; font-weight: 600; letter-spacing: .14em; text-transform: uppercase; color: #FF7000; }
    .org-muted { color: rgba(255,255,255,.65); }
    .org-card {
        background: #0a2537;
        border: 1px solid #1F2933;
        border-radius: 8px;
        height: 100%;
        display: flex;
        flex-direction: column;
        min-width: 0;
    }
    .org-card-pad { padding: 1.15rem 1.25rem; }
    .org-grid {
        display: grid;
        grid-template-columns: 1fr;
        gap: 1rem;
    }
    @media (min-width: 640px) {
        .org-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    }
    @media (min-width: 1100px) {
        .org-grid { grid-template-columns: repeat(4, minmax(0, 1fr)); }
    }
    .org-metric { display: flex; justify-content: space-between; align-items: baseline; gap: 10px; font-size: 12.5px; }
    .org-metric + .org-metric { margin-top: 8px; }
    .org-metric-l { color: rgba(255,255,255,.65); }
    .org-metric-v { font-weight: 600; text-align: right; color: #fff; }
    .org-link { font-size: 12px; font-weight: 600; color: #FF7000; text-decoration: none; }
    .org-link:hover { color: #ff8a33; }
    .org-cta {
        display: inline-flex; align-items: center; justify-content: center;
        min-height: 40px; padding: 8px 16px; border: 1px solid #FF7000;
        color: #FF7000; font-size: 12px; font-weight: 600; text-decoration: none;
        border-radius: 4px; background: transparent; cursor: pointer;
    }
    .org-cta:hover { background: rgba(255,112,0,.12); color: #fff; }
    .org-hero-num { font-size: 1.75rem; font-weight: 700; line-height: 1.1; letter-spacing: -0.02em; color: #fff; }
    .org-hero-num.org-accent { color: #FF7000; }
    .org-hero-num.org-warn { color: #FACC15; }
    .org-stack { display: flex; flex-direction: column; gap: 8px; margin-top: 12px; flex: 1; }
    .org-support-grid {
        display: grid;
        grid-template-columns: 1fr;
        gap: 1rem;
    }
    @media (min-width: 800px) {
        .org-support-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); }
    }
    .org-table { width: 100%; border-collapse: collapse; font-size: 13px; }
    .org-table th {
        text-align: left; font-weight: 500; color: rgba(255,255,255,.55);
        padding: 8px 10px; border-bottom: 1px solid #1F2933; font-size: 11px;
        text-transform: uppercase; letter-spacing: .06em;
    }
    .org-table td {
        padding: 10px; border-bottom: 1px solid rgba(31,41,51,.85);
        vertical-align: top;
    }
    .org-table tr:last-child td { border-bottom: 0; }
    .org-chip {
        display: inline-flex; padding: 3px 8px; border: 1px solid #1F2933;
        border-radius: 4px; font-size: 11px; color: rgba(255,255,255,.75);
    }
    .org-range-btn {
        padding: 5px 10px; font-size: 11px; border: 1px solid #1F2933; border-radius: 4px;
        color: rgba(255,255,255,.55); background: transparent; cursor: pointer;
    }
    .org-range-btn.is-on { border-color: #FF7000; color: #FF7000; }
</style>

<div class="org" style="padding-bottom:1rem">
    <div style="border-bottom:1px solid #1F2933;padding-bottom:1.5rem;margin-bottom:1.25rem">
        <div style="display:flex;flex-wrap:wrap;gap:1rem;justify-content:space-between;align-items:flex-start">
            <div style="min-width:0;flex:1 1 16rem">
                <div class="org-label" style="margin-bottom:10px">
                    {{ $orgWide ? 'Organisation' : 'My systems' }}
                </div>
                <h1 style="margin:0;font-size:clamp(1.5rem,3vw,2rem);font-weight:700;letter-spacing:-0.02em;line-height:1.15">
                    {{ $orgWide ? 'Systems in detail' : 'Your services' }}
                </h1>
                <p class="org-muted" style="margin:10px 0 0;font-size:13.5px;line-height:1.5;max-width:40rem">
                    @if($orgWide)
                        Dig into devices, security, backups, licences, and open tickets for
                        <span style="color:rgba(255,255,255,.9)">{{ $client->name }}</span>.
                        For a quick status check, use the Dashboard.
                    @else
                        Tickets, security, and backup items linked to you at
                        <span style="color:rgba(255,255,255,.9)">{{ $client->name }}</span>.
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
                Organisation figures are still loading. Try again shortly, or use Refresh now.
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
