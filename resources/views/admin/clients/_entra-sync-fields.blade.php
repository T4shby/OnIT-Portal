@php
    $fieldHelps = app(\App\Services\ClientOnboardingService::class)->fieldHelps($client);
    $isEntraFree = ($client->entra_license_tier ?? 'free') === 'free';
@endphp

<div class="entra-sync-fields mt-8 border-t border-white/10 pt-6">
    <p class="portal-label mb-2">Microsoft Entra sync</p>
    <p class="mb-5 text-sm leading-relaxed text-white/75">
        Paste values from the checklist, then <strong class="text-white/90">Save client</strong>.
    </p>

    <div class="mb-4">
        <div class="mb-2 flex items-center justify-between gap-2">
            <label for="entra_license_tier" class="portal-label">Entra license tier</label>
            @if($fieldHelps['entra_license_tier'] ?? null)
                <x-field-help title="Entra license tier" :steps="$fieldHelps['entra_license_tier']" />
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

@include('admin.partials.form-field', [
    'label' => 'Entra tenant ID',
    'name' => 'entra_tenant_id',
    'value' => $client->entra_tenant_id ?? '',
    'help' => $fieldHelps['entra_tenant_id'],
])

@include('admin.partials.form-field', [
    'label' => 'Entra group ID',
    'name' => 'entra_group_id',
    'value' => $client->entra_group_id ?? '',
    'help' => $fieldHelps['entra_group_id'],
])

@include('admin.partials.form-field', [
    'label' => $isEntraFree ? 'SCIM Application (client) ID' : 'SCIM Application (client) ID (optional)',
    'name' => 'entra_superops_app_id',
    'value' => $client->entra_superops_app_id ?? '',
    'help' => $fieldHelps['entra_superops_app_id'],
])

@include('admin.partials.form-field', [
    'label' => $isEntraFree ? 'Client SSO Application (client) ID' : 'Client SSO Application (client) ID (optional)',
    'name' => 'entra_superops_sso_app_id',
    'value' => $client->entra_superops_sso_app_id ?? '',
    'help' => $fieldHelps['entra_superops_sso_app_id'],
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
</div>
