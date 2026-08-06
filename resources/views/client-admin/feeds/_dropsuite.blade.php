{{-- Dropsuite backups — DashboardFeed key: dropsuite --}}
@php
    $d = $dropsuiteSummary;
    $viewerIsTechnician = $viewerIsTechnician ?? (auth()->user()?->isTeamMember() ?? false);
    $orgWide = $organisationWide ?? true;
    $personal = method_exists($d, 'isPersonal') ? $d->isPersonal() : false;
@endphp
<x-card>
    <div class="flex items-start justify-between gap-3">
        <div class="min-w-0 w-full">
            <p class="portal-label mb-2">
                @if($personal)
                    My backup
                @else
                    Backups (Dropsuite)
                @endif
            </p>

            @if($d->hasData())
                @if($personal)
                    @if($d->lastBackupAt)
                        <p class="text-2xl font-condensed font-bold text-white leading-snug">
                            {{ $d->lastBackupAt->timezone('Europe/London')->format('d M Y H:i') }} UK
                        </p>
                        <p class="portal-body-muted text-sm mt-2 leading-relaxed">
                            Last time your mailbox was backed up
                            @if(filled($d->personalEmail))
                                ({{ $d->personalEmail }})
                            @endif
                        </p>
                        @if($d->lastBackupStatus === 'warning')
                            <p class="mt-3 text-sm text-amber-300 leading-relaxed">On IT has been notified of a backup issue for your mailbox.</p>
                        @endif
                    @else
                        <p class="text-sm portal-body-muted mt-2 leading-relaxed">
                            No backup run found for your mailbox yet.
                            @if(filled($d->personalEmail))
                                Looking for {{ $d->personalEmail }}.
                            @endif
                        </p>
                    @endif
                @else
                    @php
                        $failCount = $d->failedBackupsCount;
                        $warn = ($failCount ?? 0) > 0 || $d->lastBackupStatus === 'warning';
                    @endphp
                    <p class="text-4xl font-condensed font-bold {{ $warn ? 'text-amber-300' : 'text-white' }}">
                        {{ $d->protectedMailboxes === null ? '-' : number_format($d->protectedMailboxes) }}
                    </p>
                    <p class="portal-body-muted text-sm mt-2 leading-relaxed">Protected mailboxes</p>
                    <p class="portal-body-muted text-xs mt-3 leading-relaxed">
                        @if($d->lastBackupAt)
                            Latest backup {{ $d->lastBackupAt->timezone('Europe/London')->format('d M Y H:i') }} UK
                        @else
                            Latest backup unknown
                        @endif
                        · Status: {{ ucfirst($d->lastBackupStatus) }}
                        @if($failCount !== null)
                            · {{ number_format($failCount) }} with issues
                        @endif
                        @if($d->onedriveCount !== null)
                            · {{ number_format($d->onedriveCount) }} OneDrive
                        @endif
                    </p>
                    @if($orgWide && is_array($d->accounts ?? null) && $d->accounts !== [])
                        @php
                            $problemRows = array_values(array_filter(
                                $d->accounts,
                                static fn ($row) => is_array($row) && ! empty($row['has_errors'])
                            ));
                            $problemRows = array_slice($problemRows, 0, 5);
                        @endphp
                        @if($problemRows !== [])
                            <ul class="mt-4 space-y-1.5 border-t border-white/10 pt-3">
                                @foreach($problemRows as $row)
                                    <li class="text-xs leading-relaxed text-amber-200/90 truncate">
                                        {{ $row['email'] ?? 'Mailbox' }}
                                        @if(! empty($row['current_backup_status']))
                                            · {{ $row['current_backup_status'] }}
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    @endif
                @endif
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
