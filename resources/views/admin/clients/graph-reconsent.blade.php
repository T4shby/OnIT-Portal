<x-admin-layout>
    @include('admin.partials.header', [
        'title' => 'Graph re-consent',
        'action' => '<a href="'.route('admin.clients.index').'" class="cta-btn-ghost text-sm px-6 py-3">← Clients</a>',
    ])

    <div class="mb-6 border border-white/10 bg-white/[0.02] p-5 space-y-3">
        <p class="portal-body-muted text-sm leading-relaxed">
            After Application permissions change on <strong class="text-white/80">OnIT Portal for Portals</strong>
            (On IT tenant), each customer tenant needs Microsoft <strong class="text-white/80">Accept</strong> once more
            so new scopes (Secure Score, MFA, etc.) are granted there.
        </p>
        <p class="portal-body-muted text-sm leading-relaxed">
            Use a <strong class="text-white/80">private browser</strong> and GDAP into that customer (not On IT first).
            Do <strong class="text-white/80">not</strong> redo SCIM or Client SSO. Prefer these Accept links over
            <strong class="text-white/80">Retry Graph setup</strong> unless app IDs are missing.
        </p>
        <p class="text-xs text-white/45 leading-relaxed">
            Microsoft has no multi-tenant “one click for all customers”. A person (or GDAP session) must Accept per tenant —
            this page is the staff checklist so you never need artisan for that.
        </p>
    </div>

    <div class="admin-table-wrap">
        <table class="min-w-full">
            <thead>
                <tr>
                    <th>Client</th>
                    <th>Tenant ID</th>
                    <th class="text-right">Action</th>
                </tr>
            </thead>
            <tbody>
                @forelse($rows as $row)
                    @php /** @var \App\Models\Client $client */ $client = $row['client']; @endphp
                    <tr>
                        <td>
                            <a href="{{ route('admin.clients.edit', $client) }}" class="text-white hover:text-onit font-medium">
                                {{ $client->name }}
                            </a>
                        </td>
                        <td class="font-mono text-xs text-white/50">{{ $client->entra_tenant_id }}</td>
                        <td class="text-right whitespace-nowrap space-x-2">
                            <a
                                href="{{ $row['url'] }}"
                                target="_blank"
                                rel="noopener"
                                class="cta-btn text-sm px-4 py-2 inline-flex"
                            >Accept Graph</a>
                            <a
                                href="{{ route('admin.clients.edit', $client) }}"
                                class="cta-btn-ghost text-sm px-3 py-2 inline-flex"
                            >Edit</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="3" class="px-6 py-12">
                            <x-empty-state title="No linked tenants" description="Connect Microsoft on Edit Client first — then re-consent links appear here." />
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-admin-layout>
