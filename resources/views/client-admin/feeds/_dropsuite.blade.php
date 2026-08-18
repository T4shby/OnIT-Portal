{{-- Dropsuite backups - DashboardFeed key: dropsuite --}}
@php
    $d = $dropsuiteSummary;
    $viewerIsTechnician = $viewerIsTechnician ?? (auth()->user()?->isTeamMember() ?? false);
    $orgWide = $organisationWide ?? true;
    $personal = method_exists($d, 'isPersonal') ? $d->isPersonal() : false;
    $products = app(\App\Services\Portal\ClientProductService::class);
    $needsAm = $products->needsAccountManagerHelp($client, 'dropsuite');
    $mapped = $products->isMapped($client, 'dropsuite');
    $canOpenBackups = $orgWide && ($viewerIsTechnician || auth()->user()?->can('view-organisation-wide'));
    $title = $tileLabel ?? ($personal ? 'My backup' : 'Backups');
    $backupsUrl = $viewerIsTechnician && request()->routeIs('admin.*')
        ? route('admin.clients.dropsuite', $client)
        : route('client-admin.backups');
@endphp
<div class="org-card org-card-pad">
    <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:8px;margin-bottom:4px">
        <p class="org-label" style="margin:0">{{ $title }}</p>
        @if($d->hasData() && $canOpenBackups && ! $personal)
            <a href="{{ $backupsUrl }}" class="org-link" title="Online backups">→</a>
        @endif
    </div>

    @if($d->hasData())
        @if($personal)
            @if($d->lastBackupAt)
                <p class="org-hero-num" style="margin:8px 0 0;font-size:1.25rem">
                    {{ $d->lastBackupAt->timezone('Europe/London')->format('d M Y H:i') }}
                </p>
                <p class="org-muted" style="margin:6px 0 0;font-size:12px;line-height:1.4">
                    Last backup of your mailbox
                    @if(filled($d->personalEmail))
                        ({{ $d->personalEmail }})
                    @endif
                </p>
                @if($d->lastBackupStatus === 'warning')
                    <p style="margin:10px 0 0;font-size:12px;color:#FACC15;line-height:1.4">On IT has been notified of a backup issue for your mailbox.</p>
                @endif
            @else
                <p class="org-muted" style="margin:12px 0 0;font-size:13px;line-height:1.45">
                    No backup run found for your mailbox yet.
                </p>
            @endif
        @else
            @php
                $failCount = $d->failedBackupsCount ?? 0;
                $ok24 = $d->succeededLast24h;
                $fail24 = $d->failedLast24h;
                $warn = $failCount > 0 || $d->lastBackupStatus === 'warning';
            @endphp
            <p class="org-hero-num {{ $warn ? 'org-warn' : 'org-accent' }}" style="margin:8px 0 0">
                {{ $d->protectedMailboxes === null ? '-' : number_format($d->protectedMailboxes) }}
            </p>
            <p class="org-muted" style="margin:4px 0 0;font-size:12px">mailboxes protected</p>
            <div class="org-stack">
                <div class="org-metric">
                    <span class="org-metric-l">Succeeded (24h)</span>
                    <span class="org-metric-v">{{ $ok24 === null ? '-' : number_format($ok24) }}</span>
                </div>
                <div class="org-metric">
                    <span class="org-metric-l">With issues</span>
                    <span class="org-metric-v" style="{{ ($failCount ?? 0) > 0 ? 'color:#FACC15' : '' }}">
                        {{ $failCount === null ? '-' : number_format($failCount) }}
                    </span>
                </div>
                @if(($d->onedriveCount ?? 0) > 0 || ($d->sharepointCount ?? 0) > 0)
                    <div class="org-metric">
                        <span class="org-metric-l">OneDrive / SharePoint</span>
                        <span class="org-metric-v">
                            {{ number_format($d->onedriveCount ?? 0) }} / {{ number_format($d->sharepointCount ?? 0) }}
                        </span>
                    </div>
                @endif
            </div>
            @if($d->lastBackupAt)
                <p class="org-muted" style="margin:12px 0 0;font-size:11px">
                    Latest {{ $d->lastBackupAt->timezone('Europe/London')->format('d M H:i') }} UK
                    @if($fail24 !== null && $fail24 > 0)
                        · {{ number_format($fail24) }} failed (24h)
                    @endif
                </p>
            @endif
            @if($canOpenBackups)
                <a href="{{ $backupsUrl }}" class="org-link" style="margin-top:14px">Online backups →</a>
            @endif
        @endif
    @elseif($mapped && ! $viewerIsTechnician && ! $needsAm)
        <p class="org-muted" style="margin:12px 0 0;font-size:13px;line-height:1.45">
            Backup figures are not available yet.
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
