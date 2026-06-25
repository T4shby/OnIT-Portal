@php
    $fieldHelps = app(\App\Services\ClientOnboardingService::class)->fieldHelps();
@endphp

<p class="mt-6 mb-2 portal-label">Microsoft Entra sync</p>
<p class="mb-4 portal-body-muted text-xs">Licensed M365 users and shared mailboxes in the customer tenant are synced automatically. Portal user names match M365 (no suffix). The M365 directory view shows <strong class="text-white/70">(User)</strong> or <strong class="text-white/70">(Shared Mailbox)</strong> for clarity. SuperOps requesters get the same suffix via SCIM attribute mapping — not by changing M365 display names. Shared mailboxes cannot sign in to the portal but are synced to SuperOps. On <strong class="text-white/70">Entra ID Free</strong>, paste the SuperOps <strong class="text-white/70">Application (client) ID</strong> below so sync assigns users to the SCIM app. Click <strong class="text-white/70">Help</strong> next to any field for step-by-step instructions.</p>

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
    'label' => 'SuperOps Application (client) ID (Entra ID Free)',
    'name' => 'entra_superops_app_id',
    'value' => $client->entra_superops_app_id ?? '',
    'help' => $fieldHelps['entra_superops_app_id'],
])

<p class="mb-4 -mt-2 portal-body-muted text-xs">
    <strong class="text-white/70">Paste the Application (client) ID</strong> from
    <strong class="text-white/70">App registrations → your SuperOps app → Overview</strong>
    (e.g. <code class="text-white/60">8c46a344-a010-4c78-99b9-df8b9caaba2f</code>).
    Do <strong class="text-white/70">not</strong> use the <strong class="text-white/70">Object ID</strong> on that same page.
    Requires <strong class="text-white/70">Application.Read.All</strong> on the portal app (re-consent in customer tenant after adding).
</p>

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
