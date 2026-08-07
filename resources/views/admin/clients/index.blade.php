<x-admin-layout>
    @include('admin.partials.header', [
        'title' => 'Clients',
        'action' => '<a href="'.route('admin.clients.create').'" class="cta-btn text-sm px-6 py-3">Add Client</a>'
    ])

    {{-- Visual key: equal-size chips + solid status colours. --}}
    <div class="mb-5 border border-white/10 bg-white/[0.02] p-4">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between lg:gap-8">
            <div class="min-w-0 space-y-3">
                <div>
                    <p class="portal-label mb-2">Products</p>
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-2 text-xs text-white/55">
                        <span class="inline-flex items-center gap-1.5"><span class="product-chip product-chip--muted">S</span> SuperOps</span>
                        <span class="inline-flex items-center gap-1.5"><span class="product-chip product-chip--muted">M</span> Microsoft 365</span>
                        <span class="inline-flex items-center gap-1.5"><span class="product-chip product-chip--muted">H</span> Huntress</span>
                        <span class="inline-flex items-center gap-1.5"><span class="product-chip product-chip--muted">D</span> Dropsuite</span>
                    </div>
                </div>
                <div>
                    <p class="portal-label mb-2">Licence vendor</p>
                    <p class="text-xs text-white/55 leading-relaxed">
                        After the
                        <span class="product-chip-sep">·</span>
                        separator.
                        <span class="product-chip product-chip--vendor product-chip--muted">P</span>
                        = Pax8 (dashed border). Where they buy cloud licences — not an On IT product.
                    </p>
                </div>
            </div>
            <div class="shrink-0 space-y-2 border-t border-white/10 pt-3 lg:border-l lg:border-t-0 lg:pl-8 lg:pt-0">
                <p class="portal-label mb-2">Colours</p>
                <div class="flex flex-col gap-1.5 text-xs text-white/55">
                    <span class="inline-flex items-center gap-2">
                        <span class="product-chip product-chip--live">·</span>
                        Green — live / assigned
                    </span>
                    <span class="inline-flex items-center gap-2">
                        <span class="product-chip product-chip--setup">·</span>
                        Yellow — setup / link needed
                    </span>
                    <span class="inline-flex items-center gap-2">
                        <span class="product-chip product-chip--error">·</span>
                        Red — platform error
                    </span>
                    <span class="inline-flex items-center gap-2">
                        <span class="product-chip product-chip--muted">·</span>
                        Greyed out — not sold / not assigned
                    </span>
                </div>
            </div>
        </div>
    </div>

    <div class="admin-table-wrap">
        <table class="min-w-full">
            <thead>
                <tr>
                    <th>Name</th>
                    <th class="min-w-[9.5rem]">Products</th>
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
                        <td class="align-middle">@include('admin.clients._product-matrix', ['client' => $client])</td>
                        <td>{{ $client->users_count }}</td>
                        <td>
                            @php $progress = app(\App\Services\ClientOnboardingService::class)->progress($client); @endphp
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
                    <tr><td colspan="7" class="px-6 py-12"><x-empty-state title="No clients" /></td></tr>
                @endforelse
            </tbody>
        </table>
        @if($clients->hasPages())<div class="border-t border-white/10 px-6 py-4">{{ $clients->links() }}</div>@endif
    </div>
</x-admin-layout>
