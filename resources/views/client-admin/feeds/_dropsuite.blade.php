{{-- Dropsuite backups — DashboardFeed key: dropsuite --}}
@php
    $d = $dropsuiteSummary;
    $viewerIsTechnician = $viewerIsTechnician ?? (auth()->user()?->isTeamMember() ?? false);
    $orgWide = $organisationWide ?? true;
    $personal = method_exists($d, 'isPersonal') ? $d->isPersonal() : false;
    $products = app(\App\Services\Portal\ClientProductService::class);
    $needsAm = $products->needsAccountManagerHelp($client, 'dropsuite');
    $mapped = $products->isMapped($client, 'dropsuite');
    $accountRows = is_array($d->accounts ?? null) ? array_values(array_filter(
        $d->accounts,
        static fn ($row) => is_array($row)
    )) : [];
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
                            Last time <strong class="text-white/80">your</strong> mailbox was backed up
                            @if(filled($d->personalEmail))
                                ({{ $d->personalEmail }})
                            @endif
                            — not organisation totals.
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
                    <p class="portal-body-muted text-sm mt-2 leading-relaxed">Protected mailboxes (whole organisation)</p>
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

                    @if($orgWide && $accountRows !== [])
                        <div class="mt-4 border-t border-white/10 pt-3">
                            <p class="portal-label mb-2 text-white/50">Protected mailboxes &amp; last backup</p>
                            <div class="max-h-72 overflow-y-auto space-y-0 divide-y divide-white/5 pr-1">
                                @foreach($accountRows as $row)
                                    @php
                                        $rowWarn = ! empty($row['has_errors']);
                                        $lastAt = filled($row['last_backup_at'] ?? null)
                                            ? \Carbon\Carbon::parse($row['last_backup_at'])->timezone('Europe/London')->format('d M Y H:i').' UK'
                                            : 'No run yet';
                                    @endphp
                                    <div class="py-2 flex flex-col sm:flex-row sm:items-baseline sm:justify-between gap-0.5 sm:gap-3 min-w-0">
                                        <div class="min-w-0">
                                            <p class="text-sm leading-snug {{ $rowWarn ? 'text-amber-200' : 'text-white/90' }} truncate">
                                                {{ $row['email'] ?? ($row['display_name'] ?? 'Mailbox') }}
                                            </p>
                                            @if(filled($row['display_name'] ?? null) && filled($row['email'] ?? null))
                                                <p class="text-xs text-white/40 truncate">{{ $row['display_name'] }}</p>
                                            @endif
                                        </div>
                                        <div class="shrink-0 text-xs leading-snug {{ $rowWarn ? 'text-amber-300/90' : 'text-white/55' }}">
                                            {{ $lastAt }}
                                            @if(! empty($row['current_backup_status']))
                                                · {{ $row['current_backup_status'] }}
                                            @endif
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif
                @endif
            @elseif($mapped && ! $viewerIsTechnician && ! $needsAm)
                <p class="text-sm portal-body-muted mt-2 leading-relaxed">
                    @if($personal)
                        Backup figure for your mailbox is not available yet.
                    @else
                        Backup figures are not available yet — they appear after the first successful refresh.
                    @endif
                </p>
            @else
                @include('client-admin.feeds._unavailable', [
                    'viewerIsTechnician' => $viewerIsTechnician,
                    'unavailableReason' => $d->unavailableReason,
                    'pendingLabel' => 'Backup figures',
                    'needsAccountManager' => $needsAm || ! $mapped,
                ])
            @endif
        </div>
    </div>
</x-card>
