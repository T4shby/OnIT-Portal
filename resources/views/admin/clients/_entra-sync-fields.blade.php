@php
    $fieldHelps = app(\App\Services\ClientOnboardingService::class)->fieldHelps($client);
    $isEntraFree = ($client->entra_license_tier ?? 'free') === 'free';
@endphp

<div class="entra-sync-fields mt-8 border-t border-white/10 pt-6">
    <p class="portal-label mb-2">Microsoft Entra sync</p>
    <p class="mb-4 text-sm leading-relaxed text-white/75">
        <strong class="text-white/90">Fill these during Edit</strong> — not on Add Client.
        Work the checklist on the <strong class="text-white/90">right</strong> in order; paste each value here, then click
        <strong class="text-white/90">Save client</strong>.
    </p>

    <div class="mb-4">
        <div class="mb-2 flex items-center justify-between gap-2">
            <label for="entra_license_tier" class="portal-label">Customer Entra license tier</label>
            @if($fieldHelps['entra_license_tier'] ?? null)
                <x-field-help title="Customer Entra license tier" :steps="$fieldHelps['entra_license_tier']" />
            @endif
        </div>
        <select name="entra_license_tier" id="entra_license_tier" class="admin-input">
            <option value="free" @selected(old('entra_license_tier', $client->entra_license_tier ?? 'free') === 'free')>Entra ID Free</option>
            <option value="p1" @selected(old('entra_license_tier', $client->entra_license_tier ?? 'free') === 'p1')>Entra ID P1 or higher</option>
        </select>
        @error('entra_license_tier')
            <p class="mt-1 text-sm text-red-400">{{ $message }}</p>
        @enderror
    </div>

    <ul class="client-create-intro__list mb-5 text-sm">
        <li><strong class="text-onit">Step 03</strong> — Entra tenant ID + Entra group ID (create empty group in Azure; portal fills members on sync)</li>
        @if($isEntraFree)
            <li><strong class="text-onit">Step 07</strong> — SCIM Application (client) ID — required on Free</li>
        @else
            <li><strong class="text-onit">Step 07</strong> — Assign security group to SCIM app in Azure</li>
        @endif
        <li><strong class="text-onit">Step 08</strong> — Client SSO Application (client) ID</li>
        <li><strong class="text-onit">Step 09</strong> — Tick Entra sync enabled</li>
        <li><strong class="text-onit">Step 10</strong> — Dry run sync / Sync now (buttons below this form)</li>
    </ul>
    <p class="mb-4 portal-body-muted text-xs">Every customer gets a security group in step 03. On <strong class="text-white/70">Entra ID Free</strong>, SuperOps requesters come from the SuperOps app (steps 05–07) — the group is your managed-user list in M365 and is ready if they upgrade to P1 later. Click <strong class="text-white/70">Sync now</strong> in step 10 to fill the group; do not add members by hand in Azure. Click <strong class="text-white/70">Help</strong> next to any field for more detail.</p>

@include('admin.partials.form-field', [
    'label' => 'Entra tenant ID',
    'name' => 'entra_tenant_id',
    'value' => $client->entra_tenant_id ?? '',
    'help' => $fieldHelps['entra_tenant_id'],
])

@include('admin.partials.form-field', [
    'label' => 'Entra group ID (On IT Portal security group)',
    'name' => 'entra_group_id',
    'value' => $client->entra_group_id ?? '',
    'help' => $fieldHelps['entra_group_id'],
])

@include('admin.partials.form-field', [
    'label' => $isEntraFree ? 'SuperOps SCIM Application (client) ID (required on Free)' : 'SuperOps SCIM Application (client) ID (optional on P1)',
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
    'label' => $isEntraFree ? 'SuperOps Client SSO Application (client) ID (required on Free)' : 'SuperOps Client SSO Application (client) ID',
    'name' => 'entra_superops_sso_app_id',
    'value' => $client->entra_superops_sso_app_id ?? '',
    'help' => $fieldHelps['entra_superops_sso_app_id'],
])

<p class="mb-4 -mt-2 portal-body-muted text-xs">
    Copy this from the separate customer Entra SAML app created in checklist step 08.
    It is <strong class="text-white/70">not</strong> the SCIM application above and
    <strong class="text-white/70">not</strong> the retired shared Global SSO app.
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
