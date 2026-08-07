<x-admin-layout>
    @include('admin.partials.header', ['title' => 'Dropsuite — '.$client->name])

    <div class="mb-6 flex flex-wrap items-center gap-3">
        <a href="{{ route('admin.clients.edit', $client) }}" class="cta-btn-ghost text-sm px-4 py-2">← Edit client</a>
        <a href="{{ route('admin.integration-health.index') }}" class="cta-btn-ghost text-sm px-4 py-2">Integration Health</a>
        <form method="POST" action="{{ route('admin.clients.dropsuite.refresh', $client) }}" class="inline">
            @csrf
            <button type="submit" class="cta-btn text-sm px-4 py-2">Refresh backups now</button>
        </form>
    </div>

    @include('client-admin._backups-detail', ['summary' => $summary])

    @if(filled($client->dropsuite_organization_id))
        <p class="portal-body-muted mt-6 text-xs leading-relaxed max-w-2xl">
            Mapped Dropsuite organization ID: <span class="text-white/80 font-mono">{{ $client->dropsuite_organization_id }}</span>.
            If counts stay empty, check Integration Health → Dropsuite, tokens, and a
            <code class="text-white/70">high</code> queue worker.
        </p>
    @endif
</x-admin-layout>
