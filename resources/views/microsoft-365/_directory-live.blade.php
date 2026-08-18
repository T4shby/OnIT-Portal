{{-- Dynamic directory payload only (polled). Static page chrome stays put. --}}
@if($error)
    <x-alert type="danger" class="mb-6">{{ $error }}</x-alert>
    <x-card>
        <x-empty-state
            title="Directory unavailable"
            description="Check that Entra tenant ID is set, Graph permissions are granted, and admin consent was completed in the customer tenant."
        />
    </x-card>
@elseif($display && ! $directory)
    <x-card>
        <x-empty-state
            title="Directory synchronising"
            description="No cached directory yet - Microsoft Graph is loading users, licences, and mailbox types in the background. Large tenants can take several minutes. This panel updates automatically when data arrives."
        />
    </x-card>
    <p class="portal-body-muted text-xs mt-3" data-live-poll-notice>
        Updating directory data every {{ (int) ($pollSeconds ?? 5) }} seconds…
    </p>
@elseif($directory)
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
        <div class="space-y-1">
            @if($display?->lastRefreshedAt)
                <p class="portal-body-muted text-xs">
                    @if(! empty($adminContext))
                        Last success {{ $display->lastRefreshedAt->timezone('Europe/London')->format('d M Y H:i') }} UK
                        · directory cache {{ config('services.entra_sync.directory_cache_minutes', 15) }}m
                    @else
                        Directory as of {{ $display->lastRefreshedAt->timezone('Europe/London')->format('d M Y H:i') }} UK
                    @endif
                </p>
            @endif
            @if($display?->statusMessage)
                <p class="portal-body-muted text-xs {{ ! empty($adminContext) ? 'text-amber-300/90' : 'text-white/50' }}">
                    @if(! empty($adminContext))
                        {{ $display->statusMessage }}
                    @else
                        We keep a recent copy while a fresh copy loads from Microsoft.
                    @endif
                </p>
            @endif
            @if($display?->refreshInProgress)
                <p class="portal-body-muted text-xs text-sky-300">
                    @if(! empty($adminContext))
                        Refresh in progress - showing cached data below. Tables update when the new snapshot is ready (can take several minutes on large tenants).
                    @else
                        Updating the directory from Microsoft. The list below stays visible until new data is ready.
                    @endif
                </p>
                <p class="portal-body-muted text-xs" data-live-poll-notice>
                    @if(! empty($adminContext))
                        Updating directory data every {{ (int) ($pollSeconds ?? 5) }} seconds…
                    @else
                        Checking for updates…
                    @endif
                </p>
            @endif
        </div>
        @if(! $adminContext)
            @if($organisationWide ?? true)
                <form method="POST" action="{{ route('microsoft-365.directory.refresh') }}">
                    @csrf
                    <button type="submit" class="cta-btn-ghost text-sm px-6 py-3 w-full sm:w-auto text-center">
                        Refresh now
                    </button>
                </form>
            @endif
        @else
            <a href="{{ route('admin.clients.microsoft-365', ['client' => $client, 'refresh' => 1]) }}" class="cta-btn-ghost text-sm px-6 py-3 w-full sm:w-auto text-center">
                Queue refresh
            </a>
        @endif
    </div>

    <div x-data="{ tab: 'people', peopleFilter: 'all', groupFilter: 'all' }" class="space-y-6">
        <div class="flex flex-wrap gap-2 border-b border-white/10 pb-4">
            <button type="button"
                    @click="tab = 'people'"
                    :class="tab === 'people' ? 'bg-onit text-onit-ink' : 'bg-white/5 text-white/70 hover:text-white'"
                    class="px-4 py-2 text-sm font-condensed font-bold uppercase tracking-wide transition">
                People ({{ $directory->peopleCounts()['all'] }})
            </button>
            <button type="button"
                    @click="tab = 'groups'"
                    :class="tab === 'groups' ? 'bg-onit text-onit-ink' : 'bg-white/5 text-white/70 hover:text-white'"
                    class="px-4 py-2 text-sm font-condensed font-bold uppercase tracking-wide transition">
                Groups ({{ $directory->groupCounts()['all'] }})
            </button>
        </div>

        <div x-show="tab === 'people'" x-cloak>
            <div class="flex flex-wrap gap-2 mb-4">
                @foreach([
                    'all' => 'All ('.$directory->peopleCounts()['all'].')',
                    'user' => 'Users ('.$directory->peopleCounts()['users'].')',
                    'shared_mailbox' => 'Shared mailboxes ('.$directory->peopleCounts()['shared_mailboxes'].')',
                ] as $key => $label)
                    <button type="button"
                            @click="peopleFilter = '{{ $key }}'"
                            :class="peopleFilter === '{{ $key }}' ? 'border-onit text-onit' : 'border-white/15 text-white/60'"
                            class="px-3 py-1.5 text-xs border font-condensed uppercase tracking-wide">
                        {{ $label }}
                    </button>
                @endforeach
            </div>

            @if($directory->people->isEmpty())
                <x-card><x-empty-state title="No people found" description="No licensed users or shared mailboxes were returned from Graph." /></x-card>
            @else
                @php
                    $peopleRows = $directory->people->map(function (array $person) {
                        $skus = $person['licenses'] ?? [];

                        return [
                            'person' => $person,
                            'name' => \App\Services\EntraSync\EntraSyncDisplayName::stripSuffix($person['displayName'] ?? ''),
                            'licences' => \App\Services\M365\MicrosoftLicenseSkuNames::labelledSkus($skus),
                        ];
                    });
                @endphp

                <div class="m365-people-cards sm:hidden">
                    @foreach($peopleRows as $row)
                        @php $person = $row['person']; @endphp
                        <article class="portal-ticket-card" x-show="peopleFilter === 'all' || peopleFilter === '{{ $person['type'] }}'">
                            <div class="portal-ticket-card__top">
                                <span class="text-sm font-medium text-white leading-snug">{{ $row['name'] !== '' ? $row['name'] : $person['displayName'] }}</span>
                                <x-badge :variant="$person['accountEnabled'] ? 'success' : 'danger'">
                                    {{ $person['accountEnabled'] ? 'Enabled' : 'Disabled' }}
                                </x-badge>
                            </div>
                            <p class="mt-1 text-xs text-white/55 break-all">{{ $person['email'] ?? '-' }}</p>
                            <div class="portal-ticket-card__meta">
                                <x-badge variant="info">{{ $person['typeLabel'] }}</x-badge>
                                @if($person['portalLogin'])
                                    <x-badge variant="success">Portal allowed</x-badge>
                                @else
                                    <x-badge variant="default">No portal login</x-badge>
                                @endif
                            </div>
                            @if($row['licences'])
                                <div class="m365-licence-chips mt-3">
                                    @foreach($row['licences'] as $licence)
                                        <span class="m365-licence-chip" title="{{ $licence['sku'] }}">{{ $licence['label'] }}</span>
                                    @endforeach
                                </div>
                            @else
                                <p class="mt-3 text-xs text-white/40">No licences</p>
                            @endif
                        </article>
                    @endforeach
                </div>

                <div class="admin-table-wrap hidden sm:block">
                    <table class="min-w-full">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Email</th>
                                <th>Type</th>
                                <th>Status</th>
                                <th>Licences</th>
                                <th>Portal login</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($peopleRows as $row)
                                @php $person = $row['person']; @endphp
                                <tr x-show="peopleFilter === 'all' || peopleFilter === '{{ $person['type'] }}'">
                                    <td>{{ $row['name'] !== '' ? $row['name'] : $person['displayName'] }}</td>
                                    <td class="text-white/70">{{ $person['email'] ?? '-' }}</td>
                                    <td><x-badge variant="info">{{ $person['typeLabel'] }}</x-badge></td>
                                    <td>
                                        <x-badge :variant="$person['accountEnabled'] ? 'success' : 'danger'">
                                            {{ $person['accountEnabled'] ? 'Enabled' : 'Disabled' }}
                                        </x-badge>
                                    </td>
                                    <td class="text-white/70 text-sm">
                                        @if($row['licences'])
                                            <div class="m365-licence-chips">
                                                @foreach($row['licences'] as $licence)
                                                    <span class="m365-licence-chip" title="{{ $licence['sku'] }}">{{ $licence['label'] }}</span>
                                                @endforeach
                                            </div>
                                        @else
                                            -
                                        @endif
                                    </td>
                                    <td>
                                        @if($person['portalLogin'])
                                            <x-badge variant="success">Allowed</x-badge>
                                        @else
                                            <x-badge variant="default">Not allowed</x-badge>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        <div x-show="tab === 'groups'" x-cloak>
            <div class="flex flex-wrap gap-2 mb-4">
                @foreach([
                    'all' => 'All ('.$directory->groupCounts()['all'].')',
                    'security_group' => 'Security ('.$directory->groupCounts()['security_group'].')',
                    'distribution_list' => 'Distribution ('.$directory->groupCounts()['distribution_list'].')',
                    'microsoft_365_group' => 'M365 ('.$directory->groupCounts()['microsoft_365_group'].')',
                    'mail_enabled_security_group' => 'Mail-enabled security ('.$directory->groupCounts()['mail_enabled_security_group'].')',
                ] as $key => $label)
                    <button type="button"
                            @click="groupFilter = '{{ $key }}'"
                            :class="groupFilter === '{{ $key }}' ? 'border-onit text-onit' : 'border-white/15 text-white/60'"
                            class="px-3 py-1.5 text-xs border font-condensed uppercase tracking-wide">
                        {{ $label }}
                    </button>
                @endforeach
            </div>

            @if($directory->groups->isEmpty())
                <x-card><x-empty-state title="No groups found" description="No groups were returned from Microsoft Graph for this tenant." /></x-card>
            @else
                <div class="admin-table-wrap">
                    <table class="min-w-full">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Email</th>
                                <th>Type</th>
                                <th>Description</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($directory->groups as $group)
                                <tr x-show="groupFilter === 'all' || groupFilter === '{{ $group['type'] }}'">
                                    <td>{{ $group['displayName'] }}</td>
                                    <td class="text-white/70">{{ $group['email'] ?? '-' }}</td>
                                    <td><x-badge variant="default">{{ $group['typeLabel'] }}</x-badge></td>
                                    <td class="text-white/60 text-sm max-w-md truncate" title="{{ $group['description'] }}">
                                        {{ $group['description'] ?: '-' }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
@endif
