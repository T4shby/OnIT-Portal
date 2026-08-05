@php
    $refreshing = $summary->refreshInProgress
        || $m365Insights->refreshInProgress
        || $huntressSummary->refreshInProgress
        || $dropsuiteSummary->refreshInProgress;
@endphp
<div
    id="client-admin-live"
    data-should-poll="{{ $refreshing ? '1' : '0' }}"
>
    @if($refreshing)
        <x-alert type="info" class="mb-6">
            Refresh in progress. Cached metrics stay on screen — figures update when the background jobs finish (no full-page reload).
        </x-alert>
        <p class="portal-body-muted text-xs mb-4" data-live-poll-notice>Updating overview numbers every 5 seconds…</p>
    @endif

    @include('client-admin._dashboard-metrics')
</div>
