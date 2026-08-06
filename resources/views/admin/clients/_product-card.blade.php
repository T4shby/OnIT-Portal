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
        default => 'border-white/15 bg-white/[0.03] text-white/45',
    };
    $isVendor = ($meta['kind'] ?? '') === \App\Services\Portal\ClientProductService::KIND_LICENCE_VENDOR;
@endphp
<div class="rounded border border-white/10 bg-white/[0.02] p-4 space-y-3">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0 space-y-1.5">
            <p class="text-sm font-medium text-white">{{ $meta['label'] }}</p>
            <label class="inline-flex items-center gap-2 cursor-pointer">
                <input type="hidden" name="products[{{ $key }}]" value="0">
                <input
                    type="checkbox"
                    name="products[{{ $key }}]"
                    value="1"
                    class="rounded border-white/20 bg-transparent text-onit focus:ring-onit"
                    @checked($isEntitled)
                >
                <span class="text-xs text-white/70">{{ $meta['toggle_label'] ?? ($isVendor ? 'Licences via this vendor' : 'Sold to this client') }}</span>
            </label>
        </div>
        @if($client->exists)
            <span class="shrink-0 text-[10px] uppercase tracking-wide border px-2 py-0.5 font-condensed {{ $chipClass }}">
                {{ $products->statusLabel($status, $key) }}
            </span>
        @endif
    </div>
    <p class="portal-body-muted text-xs leading-relaxed">{{ $meta['mapping_hint'] }}</p>
    <div class="pt-1">
        @include($meta['form_partial'], [
            'client' => $client,
            'fieldHelps' => $fieldHelps ?? [],
            'showEntra' => $showEntra ?? true,
        ])
    </div>
</div>
