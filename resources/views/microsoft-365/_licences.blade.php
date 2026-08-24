@if(($m365Insights ?? null) && $m365Insights->hasData() && $m365Insights->topSkus !== [])
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
            </div>

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
