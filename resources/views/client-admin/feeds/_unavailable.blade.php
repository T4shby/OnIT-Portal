{{-- Client-facing unavailable copy. Technicians see the raw integration reason. --}}
@php
    $viewerIsTechnician = $viewerIsTechnician ?? (auth()->user()?->isTeamMember() ?? false);
    $reason = (string) ($unavailableReason ?? '');
    $pendingLabel = $pendingLabel ?? 'These figures';
    $needsAm = $needsAccountManager ?? true;
@endphp
<p class="text-sm portal-body-muted mt-2 leading-relaxed">
    @if($viewerIsTechnician)
        {{ $reason !== '' ? $reason : $pendingLabel.' are not available yet.' }}
    @elseif($needsAm)
        Please contact your account manager to get this sorted.
    @else
        {{ $pendingLabel }} are not available yet.
    @endif
</p>
