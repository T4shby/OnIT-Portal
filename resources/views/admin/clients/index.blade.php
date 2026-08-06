<x-admin-layout>
    @include('admin.partials.header', [
        'title' => 'Clients',
        'action' => '<a href="'.route('admin.clients.create').'" class="cta-btn text-sm px-6 py-3">Add Client</a>'
    ])

    {{-- Visual key: chips + colour meaning (not a wall of prose). --}}
    <div class="mb-5 border border-white/10 bg-white/[0.02] p-4">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between lg:gap-8">
            <div class="min-w-0 space-y-3">
                <div>
                    <p class="portal-label mb-2">Products</p>
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-2 text-xs text-white/55">
                        <span class="inline-flex items-center gap-1.5"><span class="inline-flex h-6 w-6 items-center justify-center border border-white/15 bg-white/[0.04] text-[11px] font-condensed font-semibold !text-white/50">S</span> SuperOps</span>
                        <span class="inline-flex items-center gap-1.5"><span class="inline-flex h-6 w-6 items-center justify-center border border-white/15 bg-white/[0.04] text-[11px] font-condensed font-semibold !text-white/50">M</span> Microsoft 365</span>
                        <span class="inline-flex items-center gap-1.5"><span class="inline-flex h-6 w-6 items-center justify-center border border-white/15 bg-white/[0.04] text-[11px] font-condensed font-semibold !text-white/50">H</span> Huntress</span>
                        <span class="inline-flex items-center gap-1.5"><span class="inline-flex h-6 w-6 items-center justify-center border border-white/15 bg-white/[0.04] text-[11px] font-condensed font-semibold !text-white/50">D</span> Dropsuite</span>
                    </div>
                </div>
                <div>
                    <p class="portal-label mb-2">Licence vendor</p>
                    <p class="text-xs text-white/55 leading-relaxed">
                        After the
                        <span class="mx-0.5 text-white/30">·</span>
                        separator (dashed box).
                        <span class="inline-flex h-6 min-w-[1.5rem] items-center justify-center border border-dashed border-white/20 px-1 text-[11px] font-condensed font-semibold !text-white/50">P</span>
                        = Pax8. Not an On IT product — where they buy cloud licences. More vendors can share this slot later.
                    </p>
                </div>
            </div>
            <div class="shrink-0 space-y-2 border-t border-white/10 pt-3 lg:border-l lg:border-t-0 lg:pl-8 lg:pt-0">
                <p class="portal-label mb-2">Colours</p>
                <div class="flex flex-col gap-1.5 text-xs text-white/55">
                    <span class="inline-flex items-center gap-2">
                        <span class="inline-flex h-5 w-5 items-center justify-center border border-white/15 bg-white/[0.04] text-[10px] font-condensed !text-white/40">·</span>
                        Not sold / not assigned
                    </span>
                    <span class="inline-flex items-center gap-2">
                        <span class="inline-flex h-5 w-5 items-center justify-center border border-amber-400/60 bg-amber-500/20 text-[10px] font-condensed !text-amber-300">·</span>
                        Setup / link needed
                    </span>
                    <span class="inline-flex items-center gap-2">
                        <span class="inline-flex h-5 w-5 items-center justify-center border border-emerald-400/60 bg-emerald-500/20 text-[10px] font-condensed !text-emerald-300">·</span>
                        Live / assigned
                    </span>
                    <span class="inline-flex items-center gap-2">
                        <span class="inline-flex h-5 w-5 items-center justify-center border border-red-400/60 bg-red-500/20 text-[10px] font-condensed !text-red-300">·</span>
                        Platform error
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
