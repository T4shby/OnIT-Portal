{{-- Dropsuite backups — DashboardFeed key: dropsuite --}}
@php
    $d = $dropsuiteSummary;
    $viewerIsTechnician = $viewerIsTechnician ?? (auth()->user()?->isTeamMember() ?? false);
    $orgWide = $organisationWide ?? true;
    $personal = method_exists($d, 'isPersonal') ? $d->isPersonal() : false;
    $products = app(\App\Services\Portal\ClientProductService::class);
    $needsAm = $products->needsAccountManagerHelp($client, 'dropsuite');
    $mapped = $products->isMapped($client, 'dropsuite');
    $canOpenBackups = $orgWide && ($viewerIsTechnician || auth()->user()?->can('view-organisation-wide'));
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
                        $failCount = $d->failedBackupsCount ?? 0;
                        $ok24 = $d->succeededLast24h;
                        $fail24 = $d->failedLast24h;
                        $warn = $failCount > 0 || $d->lastBackupStatus === 'warning';
                    @endphp
                    <p class="text-4xl font-condensed font-bold {{ $warn ? 'text-amber-300' : 'text-white' }}">
                        {{ $d->protectedMailboxes === null ? '-' : number_format($d->protectedMailboxes) }}
                    </p>
                    <p class="portal-body-muted text-sm mt-2 leading-relaxed">Protected mailboxes</p>

                    <div class="mt-4 grid grid-cols-2 gap-3">
                        <div>
                            <p class="text-2xl font-condensed font-bold text-white">
                                {{ $ok24 === null ? '—' : number_format($ok24) }}
                            </p>
                            <p class="text-xs portal-body-muted mt-1 leading-snug">Succeeded in last 24 hours</p>
                        </div>
                        <div>
                            <p class="text-2xl font-condensed font-bold {{ ($failCount ?? 0) > 0 ? 'text-amber-300' : 'text-white' }}">
                                {{ $failCount === null ? '—' : number_format($failCount) }}
                            </p>
                            <p class="text-xs portal-body-muted mt-1 leading-snug">With issues (open)</p>
                        </div>
                    </div>

                    <p class="portal-body-muted text-xs mt-3 leading-relaxed">
                        @if($d->lastBackupAt)
                            Latest {{ $d->lastBackupAt->timezone('Europe/London')->format('d M Y H:i') }} UK
                        @else
                            Latest backup unknown
                        @endif
                        @if(($d->onedriveCount ?? 0) > 0)
                            · {{ number_format($d->onedriveCount) }} OneDrive
                        @endif
                        @if(($d->sharepointCount ?? 0) > 0)
                            · {{ number_format($d->sharepointCount) }} SharePoint
                        @endif
                        @if($fail24 !== null && $fail24 > 0)
                            · {{ number_format($fail24) }} failed in last 24h
                        @endif
                    </p>

                    @if($canOpenBackups)
                        <a href="{{ $viewerIsTechnician && request()->routeIs('admin.*')
                                ? route('admin.clients.dropsuite', $client)
                                : route('client-admin.backups') }}"
                           class="inline-block mt-4 text-xs text-onit hover:text-white font-condensed uppercase tracking-wide">
                            View all online backups &rarr;
                        </a>
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
