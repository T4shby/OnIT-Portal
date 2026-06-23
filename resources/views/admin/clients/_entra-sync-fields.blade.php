@php
    $fieldHelps = app(\App\Services\ClientOnboardingService::class)->fieldHelps();
@endphp

<p class="mt-6 mb-2 portal-label">Microsoft Entra sync</p>
<p class="mb-4 portal-body-muted text-xs">Licensed M365 users and shared mailboxes in the customer tenant are synced automatically. Display names show <strong class="text-white/70">(User)</strong> or <strong class="text-white/70">(Shared Mailbox)</strong>. Shared mailboxes cannot sign in to the portal but are kept for SuperOps. Click <strong class="text-white/70">Help</strong> next to any field for step-by-step instructions.</p>

@include('admin.partials.form-field', [
    'label' => 'Entra tenant ID',
    'name' => 'entra_tenant_id',
    'value' => $client->entra_tenant_id ?? '',
    'help' => $fieldHelps['entra_tenant_id'],
])

@include('admin.partials.form-field', [
    'label' => 'Entra group ID (SuperOps SCIM)',
    'name' => 'entra_group_id',
    'value' => $client->entra_group_id ?? '',
    'help' => $fieldHelps['entra_group_id'],
])

@include('admin.partials.form-field', [
    'label' => 'Entra sync enabled',
    'name' => 'entra_sync_enabled',
    'type' => 'checkbox',
    'value' => $client->entra_sync_enabled ?? false,
    'help' => $fieldHelps['entra_sync_enabled'],
])

@if(isset($client) && $client->entra_synced_at)
    <p class="portal-body-muted text-xs">Last synced: {{ $client->entra_synced_at->timezone('UTC')->format('d M Y H:i') }} UTC</p>
@endif

@if(isset($client) && $client->exists && $client->hasEntraSyncConfigured())
    @if(config('services.entra_sync.enabled'))
        <div class="mt-4 flex flex-wrap gap-3">
            <form
                method="POST"
                action="{{ route('admin.clients.sync-entra', $client) }}"
                x-data="{ saving: false }"
                x-on:submit="saving = true"
            >
                @csrf
                <input type="hidden" name="dry_run" value="1">
                <button
                    type="submit"
                    class="cta-btn-ghost text-sm px-6 py-3 disabled:cursor-not-allowed disabled:opacity-70"
                    :disabled="saving"
                >
                    <span x-show="!saving">Dry run sync</span>
                    <span x-show="saving" x-cloak>Running…</span>
                </button>
            </form>
            <form
                method="POST"
                action="{{ route('admin.clients.sync-entra', $client) }}"
                x-data="{ saving: false }"
                x-on:submit="if (confirm('Run Entra sync now? Users no longer licensed (and not shared mailboxes) will be deactivated.')) { saving = true; return true; } return false;"
            >
                @csrf
                <button
                    type="submit"
                    class="cta-btn text-sm px-6 py-3 disabled:cursor-not-allowed disabled:opacity-70"
                    :disabled="saving"
                >
                    <span x-show="!saving">Sync now</span>
                    <span x-show="saving" x-cloak>Syncing…</span>
                </button>
            </form>
        </div>
    @else
        <p class="portal-body-muted mt-4 text-xs">Set <code class="text-onit">ENTRA_SYNC_ENABLED=true</code> on the server to run sync.</p>
    @endif
@elseif(isset($client) && $client->exists)
    <p class="portal-body-muted mt-4 text-xs">Save tenant ID and enable sync to run from here.</p>
@endif
