@if(isset($client) && $client->exists && $client->hasEntraSyncConfigured())
    @if(config('services.entra_sync.enabled'))
        <div class="mt-4 flex flex-wrap gap-3">
            <form method="POST" action="{{ route('admin.clients.sync-entra', $client) }}">
                @csrf
                <input type="hidden" name="dry_run" value="1">
                <button type="submit" class="cta-btn-ghost text-sm px-6 py-3">Dry run sync</button>
            </form>
            <form
                method="POST"
                action="{{ route('admin.clients.sync-entra', $client) }}"
                onsubmit="return confirm('Run Entra sync now? Users no longer licensed (and not shared mailboxes) will be deactivated.');"
            >
                @csrf
                <button type="submit" class="cta-btn text-sm px-6 py-3">Sync now</button>
            </form>
        </div>
    @else
        <p class="portal-body-muted mt-4 text-xs">Set <code class="text-onit">ENTRA_SYNC_ENABLED=true</code> on the server to run sync.</p>
    @endif
@elseif(isset($client) && $client->exists)
    <p class="portal-body-muted mt-4 text-xs">Save tenant ID and enable sync to run from here.</p>
@endif
