@if(! $adminContext)
    <section class="mb-8 sm:mb-10">
        <div class="orange-rule"></div>
        <div class="heading-stack mb-4">
            <h1 class="section-heading-white">Microsoft</h1>
            <h1 class="section-heading-orange">365 Directory</h1>
        </div>
        <p class="portal-body-muted max-w-2xl">
            Read-only view of licensed users, shared mailboxes, and groups in <strong class="text-white/80">{{ $client->name }}</strong>
            — without signing into the Microsoft 365 admin centre.
        </p>
    </section>
@else
    <p class="portal-body-muted mb-8 max-w-2xl">
        Read-only directory for <strong class="text-white/80">{{ $client->name }}</strong> — licensed users, shared mailboxes, and groups.
    </p>
@endif

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
            description="Microsoft 365 directory data is being loaded in the background. Refresh this page in a moment."
        />
    </x-card>
@elseif($directory)
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
        <div class="space-y-1">
            @if($display?->lastRefreshedAt)
                <p class="portal-body-muted text-xs">
                    Last refreshed {{ $display->lastRefreshedAt->timezone('Europe/London')->format('d M Y H:i') }} UK
                    · cached {{ config('services.entra_sync.directory_cache_minutes', 15) }} minutes
                </p>
            @endif
            @if($display?->statusMessage)
                <p class="portal-body-muted text-xs text-amber-300/90">{{ $display->statusMessage }}</p>
            @endif
            @if($display?->refreshInProgress)
                <p class="portal-body-muted text-xs">Refresh in progress.</p>
            @endif
        </div>
        @if(! $adminContext)
            <form method="POST" action="{{ route('microsoft-365.directory.refresh') }}">
                @csrf
                <button type="submit" class="cta-btn-ghost text-sm px-6 py-3 w-full sm:w-auto text-center">
                    Refresh now
                </button>
            </form>
        @else
            <a href="{{ request()->fullUrlWithQuery(['refresh' => 1]) }}" class="cta-btn-ghost text-sm px-6 py-3 w-full sm:w-auto text-center">
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
                <div class="admin-table-wrap">
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
                            @foreach($directory->people as $person)
                                <tr x-show="peopleFilter === 'all' || peopleFilter === '{{ $person['type'] }}'">
                                    <td>{{ $person['displayName'] }}</td>
                                    <td class="text-white/70">{{ $person['email'] ?? '—' }}</td>
                                    <td><x-badge variant="info">{{ $person['typeLabel'] }}</x-badge></td>
                                    <td>
                                        <x-badge :variant="$person['accountEnabled'] ? 'success' : 'danger'">
                                            {{ $person['accountEnabled'] ? 'Enabled' : 'Disabled' }}
                                        </x-badge>
                                    </td>
                                    <td class="text-white/70 text-sm max-w-xs">
                                        @if($person['licenses'])
                                            {{ implode(', ', $person['licenses']) }}
                                        @else
                                            —
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
                                    <td class="text-white/70">{{ $group['email'] ?? '—' }}</td>
                                    <td><x-badge variant="default">{{ $group['typeLabel'] }}</x-badge></td>
                                    <td class="text-white/60 text-sm max-w-md truncate" title="{{ $group['description'] }}">
                                        {{ $group['description'] ?: '—' }}
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
