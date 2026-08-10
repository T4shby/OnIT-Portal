{{-- Client-facing unavailable copy. Technicians see the raw integration reason. --}}
@php
    $viewerIsTechnician = $viewerIsTechnician ?? (auth()->user()?->isTeamMember() ?? false);
    $reason = (string) ($unavailableReason ?? '');
    $pendingLabel = $pendingLabel ?? 'These figures';
    $needsAm = $needsAccountManager ?? true;
@endphp
<p class="org-muted" style="margin:12px 0 0;font-size:13px;line-height:1.45">
    @if($viewerIsTechnician)
        {{ $reason !== '' ? $reason : $pendingLabel.' are not available yet.' }}
    @elseif($needsAm)
        Please contact your account manager to get this sorted.
    @else
        {{ $pendingLabel }} are not available yet.
    @endif
</p>
