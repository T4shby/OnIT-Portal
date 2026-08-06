{{-- Products sold + map external IDs --}}
@php
    $products = $clientProducts ?? app(\App\Services\Portal\ClientProductService::class);
    $catalog = $products->catalog();
    $entitledInput = old('products', []);
@endphp
<div class="space-y-6 {{ $fullWidth ?? false ? 'col-span-full' : '' }}">
    <div>
        <p class="portal-label mb-1">Products</p>
        <p class="portal-body-muted text-xs leading-relaxed">
            Turn on what this client pays for, then paste IDs when ready. Status: grey not sold · amber setup needed · green live · red platform/error.
        </p>
    </div>

    @foreach($catalog as $key => $meta)
        @php
            $status = $client->exists ? $products->status($client, $key) : \App\Services\Portal\ClientProductService::STATUS_NOT_SOLD;
            $defaultEntitled = $client->exists
                ? $products->isEntitled($client, $key)
                : false;
            $isEntitled = array_key_exists($key, $entitledInput)
                ? (bool) $entitledInput[$key]
                : $defaultEntitled;
            $colour = $products->statusColour($status);
            $chipClass = match ($colour) {
                'green' => 'border-emerald-400/50 text-emerald-300',
                'amber' => 'border-amber-400/50 text-amber-300',
                'red' => 'border-red-400/50 text-red-300',
                default => 'border-white/20 text-white/50',
            };
        @endphp
        <div class="border border-white/10 p-4 space-y-3">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <label class="inline-flex items-center gap-2 cursor-pointer">
                    <input type="hidden" name="products[{{ $key }}]" value="0">
                    <input
                        type="checkbox"
                        name="products[{{ $key }}]"
                        value="1"
                        class="rounded border-white/20 bg-transparent text-onit focus:ring-onit"
                        @checked($isEntitled)
                    >
                    <span class="text-sm text-white font-medium">{{ $meta['label'] }}</span>
                </label>
                @if($client->exists)
                    <span class="text-[10px] uppercase tracking-wide border px-2 py-0.5 {{ $chipClass }}">
                        {{ $products->statusLabel($status) }}
                    </span>
                @endif
            </div>
            <p class="portal-body-muted text-xs leading-relaxed">{{ $meta['mapping_hint'] }}</p>

            @if($key === 'superops')
                @include('admin.partials.form-field', [
                    'label' => 'SuperOps Account ID',
                    'name' => 'superops_account_id',
                    'value' => old('superops_account_id', $client->superops_account_id),
                    'help' => $fieldHelps['superops_account_id'] ?? null,
                ])
                @include('admin.partials.form-field', [
                    'label' => 'SuperOps SSO enabled',
                    'name' => 'superops_sso_enabled',
                    'type' => 'checkbox',
                    'value' => old('superops_sso_enabled', $client->superops_sso_enabled ?? true),
                ])
            @elseif($key === 'm365' && ($showEntra ?? true))
                <p class="portal-body-muted text-xs">
                    Entra tenant / group / SCIM fields are in the section below when editing an existing client.
                </p>
            @elseif($key === 'huntress')
                @include('admin.partials.form-field', [
                    'label' => 'Huntress Organization ID',
                    'name' => 'huntress_organization_id',
                    'value' => old('huntress_organization_id', $client->huntress_organization_id),
                    'help' => $fieldHelps['huntress_organization_id'] ?? null,
                ])
            @elseif($key === 'dropsuite')
                @include('admin.partials.form-field', [
                    'label' => 'Dropsuite Organization ID',
                    'name' => 'dropsuite_organization_id',
                    'value' => old('dropsuite_organization_id', $client->dropsuite_organization_id),
                    'help' => $fieldHelps['dropsuite_organization_id'] ?? null,
                ])
            @elseif($key === 'pax8')
                @include('admin.partials.form-field', [
                    'label' => 'Pax8 Company ID',
                    'name' => 'pax8_company_id',
                    'value' => old('pax8_company_id', $client->pax8_company_id),
                    'help' => $fieldHelps['pax8_company_id'] ?? null,
                ])
                @include('admin.partials.form-field', [
                    'label' => 'Pax8 access enabled',
                    'name' => 'pax8_sso_enabled',
                    'type' => 'checkbox',
                    'value' => old('pax8_sso_enabled', $client->pax8_sso_enabled ?? false),
                ])
            @endif
        </div>
    @endforeach
</div>
