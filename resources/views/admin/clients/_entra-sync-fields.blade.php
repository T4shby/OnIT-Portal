@php
    $fieldHelps = app(\App\Services\ClientOnboardingService::class)->fieldHelps();
@endphp

<p class="mt-6 mb-2 portal-label">Microsoft Entra sync</p>
<p class="mb-4 portal-body-muted text-xs">Licensed M365 users and shared mailboxes in the customer tenant are synced automatically. Display names show <strong class="text-white/70">(User)</strong> or <strong class="text-white/70">(Shared Mailbox)</strong>. Shared mailboxes cannot sign in to the portal but are kept for SuperOps. On <strong class="text-white/70">Entra ID Free</strong>, set SuperOps Entra app ID so sync assigns users to the SCIM app — do not add them manually in Azure. Click <strong class="text-white/70">Help</strong> next to any field for step-by-step instructions.</p>

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
    'label' => 'SuperOps Entra app ID (Entra ID Free)',
    'name' => 'entra_superops_app_id',
    'value' => $client->entra_superops_app_id ?? '',
    'help' => $fieldHelps['entra_superops_app_id'],
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
