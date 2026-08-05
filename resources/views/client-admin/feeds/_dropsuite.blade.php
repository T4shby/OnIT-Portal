{{-- Dropsuite backups — DashboardFeed key: dropsuite --}}
@php
    $d = $dropsuiteSummary;
    $viewerIsTechnician = $viewerIsTechnician ?? (auth()->user()?->isTeamMember() ?? false);
@endphp
<x-card>
    <div class="flex items-start justify-between gap-3">
        <div>
            <p class="portal-label mb-2">Backups (Dropsuite)</p>
            @if($d->hasData())
                @php
                    $failCount = $d->failedBackupsCount;
                    $warn = ($failCount ?? 0) > 0 || $d->lastBackupStatus === 'warning';
                @endphp
                <p class="text-4xl font-condensed font-bold {{ $warn ? 'text-amber-300' : 'text-white' }}">
                    {{ $d->protectedMailboxes === null ? '-' : number_format($d->protectedMailboxes) }}
                </p>
                <p class="portal-body-muted text-sm mt-2 leading-relaxed">Protected mailboxes</p>
                <p class="portal-body-muted text-xs mt-3 leading-relaxed">
                    Status: {{ ucfirst($d->lastBackupStatus) }}
                    @if($failCount !== null)
                        · {{ number_format($failCount) }} failed
                    @endif
                </p>
            @else
                <p class="text-sm portal-body-muted mt-2">
                    @if($viewerIsTechnician)
                        {{ $d->unavailableReason }}
                    @elseif(str_contains((string) $d->unavailableReason, 'API is not configured'))
                        Backup reporting is not enabled for this organisation yet.
                    @elseif(str_contains((string) $d->unavailableReason, 'not connected'))
                        Backup reporting is not linked for this organisation yet.
                    @else
                        Backup figures are not available yet.
                    @endif
                </p>
            @endif
        </div>
    </div>
</x-card>
