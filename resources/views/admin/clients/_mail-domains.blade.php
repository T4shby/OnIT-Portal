@php
    /** @var \Illuminate\Support\Collection|\Illuminate\Database\Eloquent\Collection $mailDomains */
    $mailDomains = collect($mailDomains ?? []);
    $mailDnsHistory = collect($mailDnsHistory ?? []);
    $entraMailDomains = is_array($entraMailDomains ?? null) ? $entraMailDomains : null;
    $bandColor = fn (string $band) => match ($band) {
        'green' => 'text-emerald-400',
        'yellow' => 'text-amber-300',
        'pending' => 'text-sky-300',
        default => 'text-rose-400',
    };
    $hasPrimaryField = filled($client->primary_domain);
    $coreKeys = ['mx', 'spf', 'dkim', 'dmarc'];
    $extendedKeys = ['autodiscover', 'mta_sts', 'tls_rpt', 'bimi'];
    $extendedLabels = [
        'autodiscover' => 'Autodiscover',
        'mta_sts' => 'MTA-STS',
        'tls_rpt' => 'TLS-RPT',
        'bimi' => 'BIMI',
    ];
@endphp

<div id="mail-domains" class="min-w-0">
    <div class="mb-4 flex flex-wrap items-start justify-between gap-4">
        <div class="min-w-0 flex-1">
            <h4 class="portal-label mb-1">Mail domains</h4>
            <p class="text-xs text-white/50 max-w-3xl">
                Core =
                <strong class="text-white/75">MX / SPF / DKIM / DMARC</strong>
                (green = good · yellow = weak · red = fix or knowingly hand off).
                Main domain is set with <strong class="text-white/70">Save client</strong> in Identity below.
            </p>
        </div>
        <div class="flex shrink-0 flex-wrap items-center gap-3">
            @if($mailDnsStillRed ?? false)
                <span class="text-xs uppercase tracking-wide text-rose-300">Core DNS still has red</span>
            @endif
            @if($mailDomains->isNotEmpty())
                <a href="{{ route('admin.clients.domains.export', $client) }}" class="cta-btn-ghost text-xs px-3 py-2 uppercase tracking-wide">
                    Export CSV
                </a>
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
        <div class="mb-4 border border-white/10 bg-black/20 px-3 py-3 text-xs text-white/65 space-y-2 break-words">
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
            Connected tenant, no DNS rows yet - Import from Microsoft, or Save client with a main domain below.
        </p>
    @endif

    @if($mailDomains->isNotEmpty())
        <div class="mb-4 overflow-x-auto">
            <table class="mail-dns-table min-w-full text-sm">
                <thead>
                    <tr class="text-left text-xs uppercase tracking-wide text-white/45">
                        <th class="py-2 pr-3">Domain</th>
                        <th class="py-2 pr-3">Overall</th>
                        @foreach($coreKeys as $key)
                            <th class="py-2 pr-3">{{ strtoupper($key) }}</th>
                        @endforeach
                        <th class="py-2 pr-3">Advisory</th>
                        <th class="py-2 pr-0"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($mailDomains as $domain)
                        @php
                            $result = is_array($domain->last_result) ? $domain->last_result : [];
                            $checks = is_array($result['checks'] ?? null) ? $result['checks'] : [];
                            $pending = $domain->last_checked_at === null;
                            $overall = $pending ? 'pending' : (string) ($result['overall'] ?? 'pending');
                            $provider = $domain->providerLabel();
                            $extendedPresent = 0;
                            $extendedMissing = 0;
                            foreach ($extendedKeys as $extKey) {
                                $extBand = (string) (($checks[$extKey]['band'] ?? null) ?: 'yellow');
                                if ($pending) {
                                    continue;
                                }
                                if ($extBand === 'green') {
                                    $extendedPresent++;
                                } else {
                                    $extendedMissing++;
                                }
                            }
                        @endphp
                        <tr class="border-t border-white/10 align-top">
                            <td class="py-3 pr-3">
                                <div class="font-medium text-white break-all">{{ $domain->domain }}</div>
                                @if($domain->is_primary)
                                    <div class="text-xs text-onit">Primary</div>
                                @endif
                                @if($provider)
                                    <div class="text-xs text-white/45">{{ $provider }}</div>
                                @endif
                                @if($domain->last_checked_at)
                                    <div class="text-xs text-white/40">Checked {{ $domain->last_checked_at->diffForHumans() }}</div>
                                @else
                                    <div class="text-xs text-sky-300/80">Queued / click Check DNS</div>
                                @endif
                            </td>
                            <td class="py-3 pr-3 {{ $bandColor($overall) }}">{{ strtoupper($overall) }}</td>
                            @foreach($coreKeys as $key)
                                @php
                                    $check = is_array($checks[$key] ?? null) ? $checks[$key] : [];
                                    $band = $pending ? 'pending' : (string) ($check['band'] ?? 'pending');
                                @endphp
                                <td class="py-3 pr-3">
                                    <div class="{{ $bandColor($band) }}">
                                        {{ strtoupper($band) }}
                                    </div>
                                    <div class="text-xs text-white/50">{{ $pending ? 'Waiting' : ($check['summary'] ?? '-') }}</div>
                                </td>
                            @endforeach
                            <td class="py-3 pr-3 text-xs text-white/55">
                                @if($pending)
                                    <span class="text-sky-300">PENDING</span>
                                @else
                                    <details>
                                        <summary class="cursor-pointer text-white/70 hover:text-white">
                                            {{ $extendedPresent }} present · {{ $extendedMissing }} optional
                                        </summary>
                                        <ul class="mt-2 space-y-1 text-white/50">
                                            @foreach($extendedKeys as $key)
                                                @php
                                                    $check = is_array($checks[$key] ?? null) ? $checks[$key] : [];
                                                    $band = (string) ($check['band'] ?? 'yellow');
                                                @endphp
                                                <li>
                                                    <span class="{{ $bandColor($band) }}">{{ strtoupper($band) }}</span>
                                                    {{ $extendedLabels[$key] ?? $key }}
                                                    - {{ $check['summary'] ?? '-' }}
                                                </li>
                                            @endforeach
                                        </ul>
                                        <p class="mt-2 text-white/40">
                                            Nice to have - not onboarding blockers.
                                        </p>
                                    </details>
                                @endif
                            </td>
                            <td class="py-3 pl-3 pr-0 whitespace-nowrap text-right">
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
            Main domain is <strong class="text-white">{{ $client->primary_domain }}</strong> but no DNS row yet - Save client in Identity below (or Import).
        </p>
    @else
        <p class="mb-4 text-sm text-white/55">
            No domains yet. Set main domain in Identity below and Save client, or Import from Microsoft.
        </p>
    @endif

    @php
        $mailDomainsService = app(\App\Services\MailDns\ClientDomainService::class);
        $emailAuthRequired = $mailDomainsService->emailAuthVerificationRequired($client);
        $checklist = $client->onboarding_checklist ?? [];
        $dkimSigningDone = (bool) ($checklist['mail_dkim_signing_enabled'] ?? false);
        $authTestDone = (bool) ($checklist['mail_auth_test_passed'] ?? false);
        $primaryDns = $mailDomains->firstWhere('is_primary', true) ?? $mailDomains->first();
        $primaryChecks = is_array(($primaryDns?->last_result['checks'] ?? null)) ? $primaryDns->last_result['checks'] : [];
        $bandOf = function (string $key) use ($primaryChecks, $primaryDns): string {
            if ($primaryDns === null || $primaryDns->last_checked_at === null) {
                return 'pending';
            }
            $check = is_array($primaryChecks[$key] ?? null) ? $primaryChecks[$key] : [];

            return (string) ($check['band'] ?? 'pending');
        };
    @endphp

    @if($emailAuthRequired)
        @php
            $emailAuthDone = $dkimSigningDone && $authTestDone;
            $emailAuthBand = fn (string $band) => match ($band) {
                'green' => 'font-semibold text-emerald-300',
                'yellow' => 'font-semibold text-amber-200',
                'pending' => 'font-semibold text-sky-300',
                default => 'font-semibold text-rose-300',
            };
            $emailAuthSummary = $emailAuthDone
                ? 'Both ticks done'
                : (($dkimSigningDone ? 1 : 0) + ($authTestDone ? 1 : 0)).' of 2 ticks done';
        @endphp
        <details class="mb-4 border border-onit-border bg-onit-ink px-3 py-3">
            <summary class="cursor-pointer select-none text-xs font-condensed uppercase tracking-wide text-onit">
                Email authentication verification (M365)
                <span class="ml-2 font-sans normal-case tracking-normal {{ $emailAuthDone ? 'text-emerald-300' : 'text-amber-200' }}">
                    - {{ $emailAuthSummary }}
                </span>
            </summary>
            <div class="mt-3 space-y-3 border-t border-white/10 pt-3">
                <p class="text-xs leading-relaxed text-white/75 max-w-3xl">
                    DNS records can look correct while Microsoft 365 is still not signing mail
                    (Defender <strong class="text-white">NoDKIMKeys</strong> / headers <code class="text-white/90">dkim=none</code>).
                    Tick both boxes after you verify in Defender and with a real send test.
                </p>
                <ul class="space-y-1.5 text-xs text-white/80">
                    <li>
                        <span class="{{ $emailAuthBand($bandOf('spf')) }}">{{ strtoupper($bandOf('spf')) }}</span>
                        <span class="text-white/70"> SPF DNS</span>
                    </li>
                    <li>
                        <span class="{{ $emailAuthBand($bandOf('dkim')) }}">{{ strtoupper($bandOf('dkim')) }}</span>
                        <span class="text-white/70"> DKIM DNS (selector1 / selector2 CNAMEs)</span>
                    </li>
                    <li>
                        <span class="{{ $emailAuthBand($bandOf('dmarc')) }}">{{ strtoupper($bandOf('dmarc')) }}</span>
                        <span class="text-white/70"> DMARC DNS</span>
                    </li>
                </ul>
                <div class="space-y-2 text-sm text-white/90">
                    <input type="hidden" name="checkpoints[mail_dkim_signing_enabled]" value="0" form="onboarding-checklist-form">
                    <label class="flex items-start gap-2">
                        <input
                            type="checkbox"
                            name="checkpoints[mail_dkim_signing_enabled]"
                            value="1"
                            form="onboarding-checklist-form"
                            class="mt-0.5 border-onit-border bg-onit-surface text-onit focus:ring-onit"
                            @checked($dkimSigningDone)
                        >
                        <span>
                            DKIM signing Enabled in Microsoft Defender for this domain
                            (both CNAMEs published, status <strong class="text-white">Valid</strong> - not NoDKIMKeys).
                        </span>
                    </label>
                    <input type="hidden" name="checkpoints[mail_auth_test_passed]" value="0" form="onboarding-checklist-form">
                    <label class="flex items-start gap-2">
                        <input
                            type="checkbox"
                            name="checkpoints[mail_auth_test_passed]"
                            value="1"
                            form="onboarding-checklist-form"
                            class="mt-0.5 border-onit-border bg-onit-surface text-onit focus:ring-onit"
                            @checked($authTestDone)
                        >
                        <span>
                            Test email sent from this domain - SPF, DKIM, and DMARC all pass.
                        </span>
                    </label>
                </div>
                <p class="text-[11px] text-white/55">
                    Ticks save immediately via the setup guide checklist form. Step 00 stays open until both are done (or you explicitly defer).
                </p>
            </div>
        </details>
    @endif

    <div class="space-y-3">
        @if($mailDnsHistory->isNotEmpty())
            <details class="border border-white/10 bg-black/20 px-3 py-3">
                <summary class="cursor-pointer select-none text-xs font-condensed uppercase tracking-wide text-onit">
                    Recent DNS checks ({{ $mailDnsHistory->count() }})
                </summary>
                <ul class="mt-3 space-y-1 text-xs text-white/65 break-words">
                    @foreach($mailDnsHistory as $row)
                        <li>
                            <span class="{{ $bandColor((string) $row->overall) }}">{{ strtoupper((string) $row->overall) }}</span>
                            · {{ $row->domain?->domain ?? 'domain' }}
                            · {{ $row->checked_at?->diffForHumans() ?? '-' }}
                            · {{ $row->source }}
                            @if($row->checkedBy)
                                · {{ $row->checkedBy->name }}
                            @endif
                            @if($row->provider_key)
                                · {{ $row->provider_key }}
                            @endif
                        </li>
                    @endforeach
                </ul>
            </details>
        @endif

        <details class="border border-white/10 bg-black/20 px-3 py-3">
            <summary class="cursor-pointer select-none text-xs font-condensed uppercase tracking-wide text-onit">
                Advanced - Add domain / custom DKIM
            </summary>
            <p class="mt-2 text-xs text-white/50 max-w-3xl">
                Only for extra domains (second brand, legacy domain). Primary is Save client in Identity below.
                Custom DKIM selectors are rarely needed - Check DNS already tries common selectors.
            </p>
            <form method="POST" action="{{ route('admin.clients.domains.store', $client) }}" class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                @csrf
                <div class="min-w-0">
                    <label for="extra_domain" class="portal-label mb-1 block">Add domain</label>
                    <input id="extra_domain" type="text" name="domain" value="{{ old('domain') }}" class="admin-input" placeholder="other.clientco.co.uk" required>
                </div>
                <div class="min-w-0">
                    <label for="dkim_selectors" class="portal-label mb-1 block">Extra DKIM selectors</label>
                    <input id="dkim_selectors" type="text" name="dkim_selectors" value="{{ old('dkim_selectors') }}" class="admin-input" placeholder="optional, only if unusual">
                </div>
                <div class="flex flex-wrap items-end gap-3 sm:col-span-2">
                    <label class="flex items-center gap-2 text-sm text-white/70">
                        <input type="checkbox" name="make_primary" value="1" class="border-onit-border bg-onit-surface text-onit focus:ring-onit">
                        Make primary
                    </label>
                    <button type="submit" class="cta-btn-ghost text-sm px-4 py-2">Add domain</button>
                </div>
            </form>
        </details>
    </div>
</div>
