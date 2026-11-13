@if(($m365Insights ?? null) && $m365Insights->hasData() && $m365Insights->topSkus !== [])
    @php
        $licencePrices = app(\App\Services\M365\MicrosoftLicenseListPrices::class);
    @endphp
    @include('partials.org-theme-styles')
    <section class="org mb-10">
        <div style="display:flex;flex-wrap:wrap;align-items:baseline;justify-content:space-between;gap:10px;margin-bottom:12px">
            <h2 class="org-label" style="margin:0">Microsoft 365 licences</h2>
            @if($canExportDirectory ?? false)
                @include('microsoft-365._export-buttons', [
                    'adminContext' => $adminContext ?? false,
                    'client' => $client ?? null,
                ])
            @endif
        </div>

        <div class="org-card org-card-pad">
            <div class="org-support-grid" style="margin-bottom:1.25rem">
                <div>
                    <p class="org-muted" style="margin:0 0 4px;font-size:11px">Licensed users</p>
                    <p style="margin:0;font-size:1.35rem;font-weight:700">
                        {{ $m365Insights->licensedUserCount === null ? '-' : number_format($m365Insights->licensedUserCount) }}
                    </p>
                    <p class="org-muted" style="margin:4px 0 0;font-size:11px">User mailboxes only</p>
                </div>
                <div>
                    <p class="org-muted" style="margin:0 0 4px;font-size:11px">Paid seats assigned</p>
                    <p style="margin:0;font-size:1.35rem;font-weight:700">
                        {{ $m365Insights->totalSeatsAssigned === null ? '-' : number_format($m365Insights->totalSeatsAssigned) }}
                        @if($m365Insights->totalSeatsPurchased !== null)
                            <span class="org-muted" style="font-size:1rem;font-weight:500">/ {{ number_format($m365Insights->totalSeatsPurchased) }}</span>
                        @endif
                    </p>
                </div>
                <div>
                    <p class="org-muted" style="margin:0 0 4px;font-size:11px">Paid utilisation</p>
                    <p class="org-hero-num org-accent" style="margin:0;font-size:1.35rem">
                        {{ $m365Insights->overallUtilizationPct === null ? '-' : number_format($m365Insights->overallUtilizationPct, 0).'%' }}
                    </p>
                </div>
                @if($m365Insights->licenceMonthlyPence > 0)
                    <div>
                        <p class="org-muted" style="margin:0 0 4px;font-size:11px">Licence investment</p>
                        <p style="margin:0;font-size:1.35rem;font-weight:700">{{ $licencePrices->formatPence($m365Insights->licenceMonthlyPence) }}</p>
                        <p class="org-muted" style="margin:4px 0 0;font-size:11px">
                            per month · {{ $licencePrices->formatPence($m365Insights->licenceAnnualPence) }} a year
                        </p>
                    </div>
                @endif
            </div>
            @if($m365Insights->licenceMonthlyPence > 0 || $m365Insights->licenceUnpricedCount > 0)
                <p class="org-muted" style="margin:0 0 14px;font-size:11px;line-height:1.45">
                    Microsoft UK list price ({{ \App\Services\M365\MicrosoftLicenseListPrices::AS_OF }}, ex VAT, annual commitment). This is not the invoice. Pax8 discounts are not included.
                    @if($m365Insights->licenceUnpricedCount > 0)
                        {{ number_format($m365Insights->licenceUnpricedCount) }} paid {{ $m365Insights->licenceUnpricedCount === 1 ? 'licence is' : 'licences are' }} not in the price guide, so {{ $m365Insights->licenceUnpricedCount === 1 ? 'it is' : 'they are' }} left out of the total.
                    @endif
                </p>
            @endif

            <div style="display:flex;flex-direction:column;gap:12px">
                @foreach($m365Insights->topSkus as $sku)
                    <div>
                        <div style="display:flex;justify-content:space-between;gap:12px;font-size:12px;margin-bottom:4px">
                            <span style="color:rgba(255,255,255,.85)">{{ $sku['displayName'] ?? $sku['skuPartNumber'] }}</span>
                            <span class="org-muted" style="flex:none">
                                {{ $sku['assigned'] }} / {{ $sku['purchased'] }}
                                @if(($sku['countsTowardUtilisation'] ?? true) === false)
                                    · Free / trial
                                @else
                                    ({{ number_format($sku['utilizationPct'], 0) }}%)
                                    @php $quote = $licencePrices->quote($sku); @endphp
                                    @if($quote['line_pence'] !== null)
                                        · {{ $licencePrices->formatPence($quote['line_pence']) }}/mo
                                    @endif
                                @endif
                            </span>
                        </div>
                        <div style="height:6px;background:rgba(255,255,255,.08);border-radius:999px;overflow:hidden">
                            <div style="height:100%;width:{{ min(100, $sku['utilizationPct']) }}%;background:#FF7000;border-radius:999px"></div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </section>
@endif
