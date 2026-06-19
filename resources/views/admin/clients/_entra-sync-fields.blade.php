<p class="mt-6 mb-2 portal-label">Microsoft Entra sync</p>
<p class="mb-4 portal-body-muted text-xs">Users in the security group are created and deactivated in the portal automatically.</p>

@include('admin.partials.form-field', [
    'label' => 'Entra tenant ID',
    'name' => 'entra_tenant_id',
    'value' => $client->entra_tenant_id ?? '',
])

@include('admin.partials.form-field', [
    'label' => 'Entra group ID',
    'name' => 'entra_group_id',
    'value' => $client->entra_group_id ?? '',
])

@include('admin.partials.form-field', [
    'label' => 'Entra sync enabled',
    'name' => 'entra_sync_enabled',
    'type' => 'checkbox',
    'value' => $client->entra_sync_enabled ?? false,
])

@if(isset($client) && $client->entra_synced_at)
    <p class="portal-body-muted text-xs">Last synced: {{ $client->entra_synced_at->timezone('UTC')->format('d M Y H:i') }} UTC</p>
@endif

@if(isset($client) && $client->exists && $client->hasEntraSyncConfigured())
    @if(config('services.entra_sync.enabled'))
        <div class="mt-4 flex flex-wrap gap-3">
            <form method="POST" action="{{ route('admin.clients.sync-entra', $client) }}">
                @csrf
                <input type="hidden" name="dry_run" value="1">
                <button type="submit" class="cta-btn-ghost text-sm px-6 py-3">Dry run sync</button>
            </form>
            <form method="POST" action="{{ route('admin.clients.sync-entra', $client) }}" onsubmit="return confirm('Run Entra sync now? Users not in the group will be deactivated.');">
                @csrf
                <button type="submit" class="cta-btn text-sm px-6 py-3">Sync now</button>
            </form>
        </div>
    @else
        <p class="portal-body-muted mt-4 text-xs">Set <code class="text-onit">ENTRA_SYNC_ENABLED=true</code> on the server to run sync.</p>
    @endif
@elseif(isset($client) && $client->exists)
    <p class="portal-body-muted mt-4 text-xs">Save tenant ID, group ID, and enable sync to run from here.</p>
@endif
