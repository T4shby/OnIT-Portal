{{-- Shared tables for org-wide Dropsuite backups (Client Admin + staff). --}}
@php
    $d = $summary;
    $accounts = is_array($d->accounts ?? null) ? $d->accounts : [];
    $onedrives = is_array($d->onedrives ?? null) ? $d->onedrives : [];
    $sharepoints = is_array($d->sharepoints ?? null) ? $d->sharepoints : [];
    $pageSize = 25;
@endphp

@if(! $d->hasData())
    <x-card>
        <p class="text-sm portal-body-muted leading-relaxed">
            {{ $d->unavailableReason ?? 'Backup inventory is not loaded yet. Use Refresh if available, or wait for prewarm.' }}
        </p>
    </x-card>
@else
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
        <x-card>
            <p class="text-xs portal-body-muted mb-1">Protected mailboxes</p>
            <p class="text-3xl font-condensed font-bold text-white">{{ number_format($d->protectedMailboxes ?? 0) }}</p>
        </x-card>
        <x-card>
            <p class="text-xs portal-body-muted mb-1">Succeeded (24h)</p>
            <p class="text-3xl font-condensed font-bold text-white">{{ number_format($d->succeededLast24h ?? 0) }}</p>
        </x-card>
        <x-card>
            <p class="text-xs portal-body-muted mb-1">With issues</p>
            <p class="text-3xl font-condensed font-bold {{ ($d->failedBackupsCount ?? 0) > 0 ? 'text-amber-300' : 'text-white' }}">
                {{ number_format($d->failedBackupsCount ?? 0) }}
            </p>
        </x-card>
        <x-card>
            <p class="text-xs portal-body-muted mb-1">OneDrive / SharePoint</p>
            <p class="text-3xl font-condensed font-bold text-white">
                {{ number_format($d->onedriveCount ?? count($onedrives)) }}
                <span class="text-white/40 text-xl">/</span>
                {{ number_format($d->sharepointCount ?? count($sharepoints)) }}
            </p>
        </x-card>
    </div>

    <div x-data="{ tab: 'mailboxes', page: 1, perPage: {{ $pageSize }} }" class="space-y-6">
        <div class="flex flex-wrap gap-2">
            @foreach([
                'mailboxes' => 'Mailboxes ('.count($accounts).')',
                'onedrive' => 'OneDrive ('.count($onedrives).')',
                'sharepoint' => 'SharePoint ('.count($sharepoints).')',
            ] as $key => $label)
                <button type="button"
                        @click="tab = '{{ $key }}'; page = 1"
                        :class="tab === '{{ $key }}' ? 'border-onit text-onit' : 'border-white/15 text-white/60'"
                        class="px-3 py-1.5 text-xs border font-condensed uppercase tracking-wide">
                    {{ $label }}
                </button>
            @endforeach
        </div>

        @foreach([
            'mailboxes' => ['rows' => $accounts, 'kind' => 'mailbox'],
            'onedrive' => ['rows' => $onedrives, 'kind' => 'secondary'],
            'sharepoint' => ['rows' => $sharepoints, 'kind' => 'secondary'],
        ] as $tabKey => $meta)
            @php
                $rowsJson = \Illuminate\Support\Js::from(array_values(array_map(static function (array $row) use ($meta): array {
                    if ($meta['kind'] === 'mailbox') {
                        $lastAt = filled($row['last_backup_at'] ?? null)
                            ? \Carbon\Carbon::parse($row['last_backup_at'])->timezone('Europe/London')->format('d M Y H:i').' UK'
                            : 'No run yet';

                        return [
                            'title' => (string) ($row['email'] ?? 'Mailbox'),
                            'subtitle' => (string) ($row['display_name'] ?? ''),
                            'last_at' => $lastAt,
                            'status' => (string) ($row['current_backup_status'] ?? ''),
                            'has_errors' => ! empty($row['has_errors']),
                        ];
                    }

                    $lastAt = filled($row['last_backup_at'] ?? null)
                        ? \Carbon\Carbon::parse($row['last_backup_at'])->timezone('Europe/London')->format('d M Y H:i').' UK'
                        : 'No run yet';

                    return [
                        'title' => (string) ($row['name'] ?? 'Item'),
                        'subtitle' => (string) ($row['email'] ?? ''),
                        'last_at' => $lastAt,
                        'status' => (string) ($row['status'] ?? ''),
                        'has_errors' => ! empty($row['has_errors']),
                    ];
                }, $meta['rows'])));
            @endphp
            <div x-show="tab === '{{ $tabKey }}'" x-cloak
                 x-data="{ rows: {{ $rowsJson }} }">
                <template x-if="rows.length === 0">
                    <x-card>
                        <p class="text-sm portal-body-muted">Nothing returned from Dropsuite for this category yet.</p>
                    </x-card>
                </template>
                <template x-if="rows.length > 0">
                    <x-card>
                        <div class="flex flex-wrap items-center justify-between gap-2 mb-4">
                            <p class="text-xs text-white/50"
                               x-text="'Showing ' + Math.min(rows.length, (page - 1) * perPage + 1) + '–' + Math.min(page * perPage, rows.length) + ' of ' + rows.length"></p>
                            <div class="flex items-center gap-2" x-show="rows.length > perPage">
                                <button type="button"
                                        class="text-xs border border-white/15 px-3 py-1 uppercase font-condensed tracking-wide disabled:opacity-30"
                                        :disabled="page <= 1"
                                        @click="page = Math.max(1, page - 1)">Prev</button>
                                <span class="text-xs text-white/50" x-text="'Page ' + page + ' / ' + Math.ceil(rows.length / perPage)"></span>
                                <button type="button"
                                        class="text-xs border border-white/15 px-3 py-1 uppercase font-condensed tracking-wide disabled:opacity-30"
                                        :disabled="page >= Math.ceil(rows.length / perPage)"
                                        @click="page = Math.min(Math.ceil(rows.length / perPage), page + 1)">Next</button>
                            </div>
                        </div>
                        <div class="divide-y divide-white/5">
                            <template x-for="(row, idx) in rows.slice((page - 1) * perPage, page * perPage)" :key="row.title + '-' + idx">
                                <div class="py-3 flex flex-col sm:flex-row sm:items-baseline sm:justify-between gap-1 sm:gap-4 min-w-0">
                                    <div class="min-w-0">
                                        <p class="text-sm truncate" :class="row.has_errors ? 'text-amber-200' : 'text-white/90'" x-text="row.title"></p>
                                        <p class="text-xs text-white/40 truncate" x-show="row.subtitle" x-text="row.subtitle"></p>
                                    </div>
                                    <div class="shrink-0 text-xs" :class="row.has_errors ? 'text-amber-300/90' : 'text-white/55'">
                                        <span x-text="row.last_at"></span>
                                        <span x-show="row.status" x-text="' · ' + row.status"></span>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </x-card>
                </template>
            </div>
        @endforeach
    </div>
@endif
