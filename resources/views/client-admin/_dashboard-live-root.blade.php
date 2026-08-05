@php
    $refreshing = $summary->refreshInProgress
        || $m365Insights->refreshInProgress
        || $huntressSummary->refreshInProgress
        || $dropsuiteSummary->refreshInProgress;
    $viewerIsTechnician = auth()->user()?->isTeamMember() ?? false;
@endphp
<div
    id="client-admin-live"
    data-should-poll="{{ $refreshing ? '1' : '0' }}"
>
    @if($refreshing)
        @if($viewerIsTechnician)
            <x-alert type="info" class="mb-6">
                Background refresh running (SuperOps / M365 / Huntress / Dropsuite as queued).
                Cache stays on screen; live partial updates every 5s — no full-page reload.
            </x-alert>
            <p class="portal-body-muted text-xs mb-4" data-live-poll-notice>Polling metrics every 5s while jobs run…</p>
        @else
            <x-alert type="info" class="mb-6">
                Updating your overview. Current numbers stay visible until the new figures are ready.
            </x-alert>
            <p class="portal-body-muted text-xs mb-4" data-live-poll-notice>Checking for updated numbers…</p>
        @endif
    @endif

    @include('client-admin._dashboard-metrics')
</div>
