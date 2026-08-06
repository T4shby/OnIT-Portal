<x-admin-layout>
    @include('admin.partials.header', [
        'title' => 'Clients',
        'action' => '<a href="'.route('admin.clients.create').'" class="cta-btn text-sm px-6 py-3">Add Client</a>'
    ])

    <div class="mb-4 space-y-1">
        <p class="portal-body-muted text-xs leading-relaxed">
            <span class="text-white/60">Products</span>
            S SuperOps · M Microsoft 365 · H Huntress · D Dropsuite —
            grey not sold · amber setup needed · green live · red error.
        </p>
        <p class="portal-body-muted text-xs leading-relaxed">
            <span class="text-white/60">Lic</span>
            Licence vendor (P now = Pax8) — grey not assigned · amber link needed · green assigned.
            Not an On IT product; where they buy cloud licences. More vendors can be added modularly.
        </p>
    </div>

    <div class="admin-table-wrap">
        <table class="min-w-full">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Products / Lic</th>
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
                        <td>@include('admin.clients._product-matrix', ['client' => $client])</td>
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
