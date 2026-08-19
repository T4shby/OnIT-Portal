@php
    $viewerIsTechnician = auth()->user()?->isTeamMember() ?? false;
    $refreshing = $summary->refreshInProgress;
@endphp
<div
    id="client-admin-live"
    data-should-poll="{{ $refreshing ? '1' : '0' }}"
>
    @if($refreshing)
        @if($viewerIsTechnician)
            <x-alert type="info" class="mb-6">
                Background SuperOps refresh is running. Cache stays on screen; this panel updates every 5s.
            </x-alert>
            <p class="portal-body-muted text-xs mb-4" data-live-poll-notice>Polling metrics every 5s while jobs run…</p>
        @else
            <x-alert type="info" class="mb-6">
                Updating support figures. Current numbers stay visible until the new figures are ready.
            </x-alert>
            <p class="portal-body-muted text-xs mb-4" data-live-poll-notice>Checking for updated numbers…</p>
        @endif
    @endif

    @include('client-admin._dashboard-metrics')
</div>
