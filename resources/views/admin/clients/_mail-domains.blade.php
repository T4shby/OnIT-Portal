@php
    /** @var \Illuminate\Support\Collection|\Illuminate\Database\Eloquent\Collection $mailDomains */
    $mailDomains = collect($mailDomains ?? []);
    $entraMailDomains = is_array($entraMailDomains ?? null) ? $entraMailDomains : null;
    $bandColor = fn (string $band) => match ($band) {
        'green' => 'text-emerald-400',
        'yellow' => 'text-amber-300',
        default => 'text-rose-400',
    };
    $hasPrimaryField = filled($client->primary_domain);
@endphp

<div id="mail-domains">
    <div class="mb-4 flex flex-wrap items-start justify-between gap-4">
        <div class="min-w-0 flex-1">
            <h3 class="admin-section-title mb-1 text-base">Mail domains</h3>
            <p class="text-xs text-white/50 max-w-3xl">
                Step 00. Green = correct · Yellow = weak · Red = missing.
                Main domain is saved with <strong class="text-white/70">Save client</strong> above.
                This card is Import, extra domains, and DNS checks only.
            </p>
        </div>
        <div class="flex shrink-0 flex-wrap items-center gap-3">
            @if($mailDnsStillRed ?? false)
                <span class="text-xs uppercase tracking-wide text-rose-300">DNS still has red</span>
            @endif
            @if(filled($client->entra_tenant_id))
                <form method="POST" action="{{ route('admin.clients.domains.sync-entra', $client) }}">
                    @csrf
                    <button type="submit" class="cta-btn-ghost text-xs px-3 py-2 uppercase tracking-wide">
                        Import from Microsoft
                    </button>
                </form>
            @endif
        </div>
    </div>

    @if(is_array($entraMailDomains))
        <div class="mb-4 border border-white/10 bg-black/20 px-3 py-3 text-xs text-white/65 space-y-2">
            <p class="text-white/80">
                Last Microsoft pull
                @if(! empty($entraMailDomains['fetched_at']))
                    ({{ \Illuminate\Support\Carbon::parse($entraMailDomains['fetched_at'])->diffForHumans() }})
                @endif
                : {{ $entraMailDomains['summary'] ?? '' }}
            </p>
            @php
                $cross = is_array($entraMailDomains['cross_check'] ?? null) ? $entraMailDomains['cross_check'] : [];
            @endphp
            @if(! empty($cross['entra_only']))
                <p>
                    In Entra / mailboxes but not listed here:
                    <span class="text-amber-200">{{ implode(', ', $cross['entra_only']) }}</span>
                </p>
            @endif
            @if(! empty($cross['portal_only']))
                <p>
                    In portal but not seen in Entra / mailboxes:
                    <span class="text-amber-200">{{ implode(', ', $cross['portal_only']) }}</span>
                </p>
            @endif
            @if(! empty($entraMailDomains['domains_api_error']))
                <p class="text-white/45">
                    Verified-domain API unavailable (Domain.Read.All). Mailbox suffixes still work with User.Read.All.
                </p>
            @endif
        </div>
    @elseif(filled($client->entra_tenant_id) && $mailDomains->isEmpty())
        <p class="mb-4 text-sm text-amber-100/90">
            Connected tenant, no DNS rows yet - Import from Microsoft, or Save client with a main domain.
        </p>
    @endif

    @if($mailDomains->isNotEmpty())
        <div class="mb-4 overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead>
                    <tr class="text-left text-xs uppercase tracking-wide text-white/45">
                        <th class="py-2 pr-3">Domain</th>
                        <th class="py-2 pr-3">Overall</th>
                        <th class="py-2 pr-3">MX</th>
                        <th class="py-2 pr-3">SPF</th>
                        <th class="py-2 pr-3">DKIM</th>
                        <th class="py-2 pr-3">DMARC</th>
                        <th class="py-2 pr-3"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($mailDomains as $domain)
                        @php
                            $result = is_array($domain->last_result) ? $domain->last_result : [];
                            $checks = is_array($result['checks'] ?? null) ? $result['checks'] : [];
                            $overall = (string) ($result['overall'] ?? 'red');
                        @endphp
                        <tr class="border-t border-white/10 align-top">
                            <td class="py-3 pr-3">
                                <div class="font-medium text-white">{{ $domain->domain }}</div>
                                @if($domain->is_primary)
                                    <div class="text-xs text-onit">Primary</div>
                                @endif
                                @if($domain->last_checked_at)
                                    <div class="text-xs text-white/40">Checked {{ $domain->last_checked_at->diffForHumans() }}</div>
                                @else
                                    <div class="text-xs text-white/40">Not checked yet</div>
                                @endif
                            </td>
                            <td class="py-3 pr-3 {{ $bandColor($overall) }}">{{ strtoupper($overall) }}</td>
                            @foreach(['mx', 'spf', 'dkim', 'dmarc'] as $key)
                                @php $check = is_array($checks[$key] ?? null) ? $checks[$key] : []; @endphp
                                <td class="py-3 pr-3">
                                    <div class="{{ $bandColor((string) ($check['band'] ?? 'red')) }}">
                                        {{ strtoupper((string) ($check['band'] ?? 'red')) }}
                                    </div>
                                    <div class="text-xs text-white/50 max-w-[10rem]">{{ $check['summary'] ?? 'Not checked' }}</div>
                                </td>
                            @endforeach
                            <td class="py-3 pr-0 whitespace-nowrap">
                                <form method="POST" action="{{ route('admin.clients.domains.check', [$client, $domain]) }}" class="inline">
                                    @csrf
                                    <button type="submit" class="text-xs text-onit hover:text-white uppercase tracking-wide">Check DNS</button>
                                </form>
                                @unless($domain->is_primary)
                                    <form method="POST" action="{{ route('admin.clients.domains.destroy', [$client, $domain]) }}" class="inline ml-3" onsubmit="return confirm('Remove {{ $domain->domain }}?')">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="text-xs text-white/45 hover:text-rose-300 uppercase tracking-wide">Remove</button>
                                    </form>
                                @endunless
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @elseif($hasPrimaryField)
        <p class="mb-4 text-sm text-amber-100/90">
            Main domain is <strong class="text-white">{{ $client->primary_domain }}</strong> but no DNS row yet - Save client above (or Import).
        </p>
    @else
        <p class="mb-4 text-sm text-white/55">
            No domains yet. Set main domain above and Save client, or Import from Microsoft.
        </p>
    @endif

    <form method="POST" action="{{ route('admin.clients.domains.store', $client) }}" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        @csrf
        <div>
            <label for="extra_domain" class="portal-label mb-1 block">Add domain</label>
            <input id="extra_domain" type="text" name="domain" value="{{ old('domain') }}" class="admin-input" placeholder="mail.clientco.co.uk" required>
        </div>
        <div>
            <label for="dkim_selectors" class="portal-label mb-1 block">Extra DKIM selectors</label>
            <input id="dkim_selectors" type="text" name="dkim_selectors" value="{{ old('dkim_selectors') }}" class="admin-input" placeholder="optional, comma-separated">
        </div>
        <div class="flex items-end gap-3 sm:col-span-2">
            <label class="flex items-center gap-2 text-sm text-white/70">
                <input type="checkbox" name="make_primary" value="1" class="border-onit-border bg-onit-surface text-onit focus:ring-onit">
                Make primary
            </label>
            <button type="submit" class="cta-btn-ghost text-sm px-4 py-2">Add domain</button>
        </div>
    </form>
</div>
