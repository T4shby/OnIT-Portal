{{--
  Modular product / licence-vendor assignment.
  Services = sold MSP products. Licence vendors = where they buy cloud licences (Pax8 now; more later).
--}}
@php
    $products = $clientProducts ?? app(\App\Services\Portal\ClientProductService::class);
    $services = $products->serviceCatalog();
    $vendors = $products->licenceVendorCatalog();
    $showEntra = $showEntra ?? true;
@endphp

<div class="space-y-8 {{ $fullWidth ?? false ? 'col-span-full' : '' }}">
    <section class="space-y-4">
        <div>
            <p class="portal-label mb-1">Portal products</p>
            <p class="portal-body-muted text-xs leading-relaxed">
                What On IT sells this client. Toggle sold, then paste IDs when ready.
                Status: grey not sold · amber setup needed · green live · red platform/error.
            </p>
        </div>
        <div class="space-y-4">
            @foreach($services as $key => $meta)
                @include('admin.clients._product-card', [
                    'key' => $key,
                    'meta' => $meta,
                    'client' => $client,
                    'products' => $products,
                    'fieldHelps' => $fieldHelps ?? [],
                    'showEntra' => $showEntra,
                ])
            @endforeach
        </div>
    </section>

    <section class="space-y-4 border-t border-white/10 pt-6">
        <div>
            <p class="portal-label mb-1">Licence vendor</p>
            <p class="portal-body-muted text-xs leading-relaxed">
                Where this client buys Microsoft / cloud licences (marketplace reseller) — not an On IT product.
                Tick when assigned; paste company ID for portal launch. Add further vendors in the catalog
                the same way as Pax8.
            </p>
        </div>
        <div class="space-y-4">
            @forelse($vendors as $key => $meta)
                @include('admin.clients._product-card', [
                    'key' => $key,
                    'meta' => $meta,
                    'client' => $client,
                    'products' => $products,
                    'fieldHelps' => $fieldHelps ?? [],
                    'showEntra' => $showEntra,
                ])
            @empty
                <p class="portal-body-muted text-xs">No licence vendors in the catalog yet.</p>
            @endforelse
        </div>
    </section>
</div>
