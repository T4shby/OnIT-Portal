@php
    $fieldHelps = app(\App\Services\ClientOnboardingService::class)->fieldHelps();
@endphp

<div class="entra-sync-fields mt-8 border-t border-white/10 pt-6">
    <p class="portal-label mb-2">Microsoft Entra sync</p>
    <p class="mb-4 text-sm leading-relaxed text-white/75">
        <strong class="text-white/90">Fill these during Edit</strong> — not on Add Client.
        Work the checklist on the <strong class="text-white/90">right</strong> in order; paste each value here, then click
        <strong class="text-white/90">Save client</strong>.
    </p>
    <ul class="client-create-intro__list mb-5 text-sm">
        <li><strong class="text-onit">Step 03</strong> — Entra tenant ID + Entra group ID (after you create the empty security group in Azure)</li>
        <li><strong class="text-onit">Step 05</strong> — SuperOps Application (client) ID (after the SCIM app exists in customer Entra)</li>
        <li><strong class="text-onit">Step 07</strong> — Tick Entra sync enabled</li>
        <li><strong class="text-onit">Step 08</strong> — Dry run sync / Sync now (buttons below this form)</li>
    </ul>
    <p class="mb-4 portal-body-muted text-xs">Licensed M365 users and shared mailboxes in the customer tenant are synced automatically. Portal user names match M365 (no suffix). The M365 directory view shows <strong class="text-white/70">(User Mailbox)</strong> or <strong class="text-white/70">(Shared Mailbox)</strong> for clarity. SuperOps requesters get the same suffix via <strong class="text-white/70">extensionAttribute1</strong> + SCIM <strong class="text-white/70">name.formatted</strong> mapping — not by changing M365 display names. Shared mailboxes cannot sign in to the portal but are synced to SuperOps. On <strong class="text-white/70">Entra ID Free</strong>, paste the SuperOps <strong class="text-white/70">Application (client) ID</strong> below so sync assigns users to the SCIM app. Click <strong class="text-white/70">Help</strong> next to any field for step-by-step instructions.</p>

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
    (format: <code class="text-white/60">xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx</code>).
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
</div>
