@php
    $status = $client->exists
        ? $products->status($client, $key)
        : \App\Services\Portal\ClientProductService::STATUS_NOT_SOLD;
    $entitledInput = old('products', []);
    $defaultEntitled = $client->exists ? $products->isEntitled($client, $key) : false;
    $isEntitled = array_key_exists($key, $entitledInput)
        ? (bool) $entitledInput[$key]
        : $defaultEntitled;
    $colour = $products->statusColour($status);
    $chipClass = match ($colour) {
        'green' => 'border-emerald-500/60 bg-emerald-500/15 text-emerald-300',
        'amber' => 'border-amber-500/60 bg-amber-500/15 text-amber-300',
        'red' => 'border-red-500/60 bg-red-500/15 text-red-300',
        default => 'border-white/15 bg-white/[0.03] text-white/55',
    };
    $isVendor = ($meta['kind'] ?? '') === \App\Services\Portal\ClientProductService::KIND_LICENCE_VENDOR;
@endphp
<div class="admin-product-card">
    <div class="flex items-start justify-between gap-2">
        <p class="min-w-0 text-sm font-medium leading-snug text-white">{{ $meta['label'] }}</p>
        @if($client->exists)
            <span class="shrink-0 text-[10px] uppercase tracking-wide border px-1.5 py-0.5 font-condensed {{ $chipClass }}">
                {{ $products->statusLabel($status, $key) }}
            </span>
        @endif
    </div>
    <label class="inline-flex items-center gap-2 cursor-pointer">
        <input type="hidden" name="products[{{ $key }}]" value="0">
        <input
            type="checkbox"
            name="products[{{ $key }}]"
            value="1"
            class="rounded border-white/20 bg-transparent text-onit focus:ring-onit"
            @checked($isEntitled)
        >
        <span class="text-xs text-white/75">{{ $meta['toggle_label'] ?? ($isVendor ? 'Licences via this vendor' : 'Sold to this client') }}</span>
    </label>
    <div class="admin-product-card__fields min-w-0">
        @include($meta['form_partial'], [
            'client' => $client,
            'fieldHelps' => $fieldHelps ?? [],
            'showEntra' => $showEntra ?? true,
            'dropsuiteOrganizations' => $dropsuiteOrganizations ?? [],
        ])
    </div>
</div>
