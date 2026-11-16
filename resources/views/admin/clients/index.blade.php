<x-admin-layout>
    @include('admin.partials.header', [
        'title' => 'Clients',
        'action' => '<div class="flex flex-wrap gap-2 justify-end">'
            .'<a href="'.route('admin.clients.dropsuite-organizations').'" class="cta-btn-ghost text-sm px-5 py-3">Dropsuite orgs</a>'
            .'<a href="'.route('admin.clients.graph-reconsent').'" class="cta-btn-ghost text-sm px-5 py-3">Graph re-consent</a>'
            .'<a href="'.route('admin.clients.create').'" class="cta-btn text-sm px-6 py-3">Add Client</a>'
            .'</div>',
    ])

    {{-- Visual key: equal-size chips + solid status colours. --}}
    <div class="mb-5 border border-white/10 bg-white/[0.02] p-4">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between lg:gap-8">
            <div class="min-w-0 space-y-3">
                <div>
                    <p class="portal-label mb-2">Products</p>
                    <p class="text-xs text-white/55 leading-relaxed">SuperOps, M365, Huntress, Dropsuite. Named pills in the Products column.</p>
                </div>
                <div>
                    <p class="portal-label mb-2">Licence</p>
                    <p class="text-xs text-white/55 leading-relaxed">
                        Its own column. Pax8 today (dashed border). Another licence vendor shows here when it is added. Not an On IT product.
                    </p>
                </div>
                <div>
                    <p class="portal-label mb-2">GDAP</p>
                    <div class="flex flex-col gap-1.5 text-xs text-white/55">
                        <span class="inline-flex items-center gap-2"><span class="product-chip product-chip--live">✓</span> Done</span>
                        <span class="inline-flex items-center gap-2"><span class="product-chip product-chip--error">×</span> Not set up</span>
                        <span class="inline-flex items-center gap-2"><span class="product-chip product-chip--setup">?</span> Cannot be checked</span>
                    </div>
                </div>
            </div>
                <div class="shrink-0 space-y-2 border-t border-white/10 pt-3 lg:border-l lg:border-t-0 lg:pl-8 lg:pt-0">
                <p class="portal-label mb-2">Colours</p>
                <div class="flex flex-col gap-1.5 text-xs text-white/55">
                    <span class="inline-flex items-center gap-2">
                        <span class="product-chip product-chip--live">·</span>
                        Green - live / assigned
                    </span>
                    <span class="inline-flex items-center gap-2">
                        <span class="product-chip product-chip--setup">·</span>
                        Yellow - setup / link needed
                    </span>
                    <span class="inline-flex items-center gap-2">
                        <span class="product-chip product-chip--error">·</span>
                        Red - platform error
                    </span>
                    <span class="inline-flex items-center gap-2">
                        <span class="product-chip product-chip--muted">·</span>
                        Greyed out - not sold / not assigned
                    </span>
                </div>
            </div>
        </div>
        <p class="mt-4 border-t border-white/10 pt-3 text-xs text-white/50 leading-relaxed max-w-4xl">
            <strong class="text-white/70 font-medium">Client home differs by sold products.</strong>
            Grey Huntress (not sold) → support-led dashboard
            (tickets + SLA as hero stats - not empty “Threats stopped”).
            Open <strong class="text-white/65 font-medium">Edit</strong> on a client for the full “What the client sees at home” panel.
        </p>
    </div>

    <div class="admin-table-wrap">
        <table class="min-w-full">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Products</th>
                    <th>Licence</th>
                    <th>GDAP</th>
                    <th>Users</th>
                    <th>Setup</th>
                    <th>Entra sync</th>
                    <th>Status</th>
                    <th class="text-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($clients as $client)
                    <tr>
                        <td>{{ $client->name }}</td>
                        <td class="align-middle">@include('admin.clients._product-matrix', ['client' => $client, 'group' => 'services'])</td>
                        <td class="align-middle">@include('admin.clients._product-matrix', ['client' => $client, 'group' => 'vendors'])</td>
                        <td class="align-middle">@include('admin.clients._gdap-mark', ['client' => $client])</td>
                        <td>{{ $client->users_count }}</td>
                        <td>
                            @php $progress = app(\App\Services\ClientOnboardingService::class)->progress($client, live: false); @endphp
                            @if($progress['percent'] === 100)
                                <x-badge variant="success">Complete</x-badge>
                            @else
                                <span class="text-white/60">{{ $progress['percent'] }}%</span>
                            @endif
                        </td>
                        <td>
                            @if($client->hasEntraSyncConfigured())
                                <x-badge variant="success">On</x-badge>
                            @else
                                <span class="text-white/40">Off</span>
                            @endif
                        </td>
                        <td><x-badge :variant="$client->is_active ? 'success' : 'danger'">{{ $client->is_active ? 'Active' : 'Inactive' }}</x-badge></td>
                        <td class="text-right">
                            @include('admin.partials.table-actions', [
                                'editRoute' => route('admin.clients.edit', $client),
                                'deleteRoute' => route('admin.clients.destroy', $client),
                            ])
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="px-6 py-12"><x-empty-state title="No clients" /></td></tr>
                @endforelse
            </tbody>
        </table>
        @if($clients->hasPages())<div class="border-t border-white/10 px-6 py-4">{{ $clients->links() }}</div>@endif
    </div>
</x-admin-layout>
