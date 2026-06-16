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
