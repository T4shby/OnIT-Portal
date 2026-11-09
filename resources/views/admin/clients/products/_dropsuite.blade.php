@php
    $orgs = $dropsuiteOrganizations ?? [];
    $currentId = old('dropsuite_organization_id', $client->dropsuite_organization_id);
@endphp
@if($orgs !== [])
    <div class="mb-3">
        <label for="dropsuite_organization_pick" class="portal-label mb-1 block">Pick from Dropsuite</label>
        <select
            id="dropsuite_organization_pick"
            class="admin-input"
            onchange="document.getElementById('dropsuite_organization_id').value = this.value"
        >
            <option value="">Choose organisation</option>
            @foreach($orgs as $org)
                <option value="{{ $org['organization_id'] }}" @selected((string) $currentId === (string) $org['organization_id'])>
                    {{ $org['name'] }} ({{ $org['organization_id'] }})
                </option>
            @endforeach
        </select>
    </div>
@endif
@include('admin.partials.form-field', [
    'label' => 'Dropsuite Organization ID',
    'name' => 'dropsuite_organization_id',
    'value' => $currentId,
    'help' => $fieldHelps['dropsuite_organization_id'] ?? null,
])
<p class="mb-0 text-xs text-white/45 leading-relaxed">
    <a href="{{ route('admin.clients.dropsuite-organizations') }}" class="text-onit hover:text-white">Import from Dropsuite</a>
    lists every org, then filter to live clients. Do not paste a user id like 360787-12.
</p>
