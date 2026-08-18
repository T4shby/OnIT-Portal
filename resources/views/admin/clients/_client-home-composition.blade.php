{{--
  Technician guide: this client’s portal home is product-shaped.
  Data: ClientHomeOverviewService::staffHomeComposition() - Brain/UIOverhaul.md
--}}
@php
    $home = $clientHomeComposition ?? null;
@endphp
@if(is_array($home))
    <div class="mt-6 border border-onit/30 bg-onit/5 p-4 sm:p-5" id="client-home-composition">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <p class="portal-label text-onit">What the client sees at home</p>
                <h3 class="mt-1 font-semibold text-white text-sm sm:text-base leading-snug">
                    {{ $home['headline'] ?? 'Client dashboard composition' }}
                </h3>
            </div>
            @php
                $mode = $home['value_strip_mode'] ?? '';
                $modeClass = $mode === 'support_led'
                    ? 'bg-sky-500/15 text-sky-200 border-sky-400/30'
                    : 'bg-emerald-500/15 text-emerald-200 border-emerald-400/30';
                $modeText = $mode === 'support_led' ? 'Support-led hero' : 'MDR + support hero';
            @endphp
            <span class="shrink-0 border px-2.5 py-1 text-[11px] font-semibold uppercase tracking-wide {{ $modeClass }}">
                {{ $modeText }}
            </span>
        </div>

        <p class="mt-3 text-sm text-white/75 leading-relaxed">
            {{ $home['value_strip_summary'] ?? '' }}
        </p>
        <p class="mt-2 text-xs text-white/55 leading-relaxed">
            {{ $home['detail'] ?? '' }}
        </p>

        @if(! empty($home['value_strip_labels']) && is_array($home['value_strip_labels']))
            <div class="mt-4">
                <p class="text-[11px] font-semibold uppercase tracking-wider text-white/40 mb-2">Hero value strip (top stats)</p>
                <div class="flex flex-wrap gap-2">
                    @foreach($home['value_strip_labels'] as $label)
                        <span class="border border-white/15 bg-white/5 px-2.5 py-1 text-xs text-white/85">{{ $label }}</span>
                    @endforeach
                </div>
            </div>
        @endif

        @if(! empty($home['columns']) && is_array($home['columns']))
            <div class="mt-4 overflow-x-auto">
                <p class="text-[11px] font-semibold uppercase tracking-wider text-white/40 mb-2">Four service tiles</p>
                <table class="min-w-full text-left text-xs">
                    <thead>
                        <tr class="text-white/40 border-b border-white/10">
                            <th class="py-2 pr-3 font-medium">Tile</th>
                            <th class="py-2 pr-3 font-medium">Entitlement</th>
                            <th class="py-2 font-medium">Client card label</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($home['columns'] as $col)
                            @php
                                $st = $col['status'] ?? '';
                                $chip = match ($st) {
                                    'live' => 'text-emerald-300',
                                    'setup_needed' => 'text-amber-300',
                                    'not_sold' => 'text-white/45',
                                    'platform_down', 'error' => 'text-rose-300',
                                    default => 'text-white/60',
                                };
                            @endphp
                            <tr class="border-b border-white/5 align-top">
                                <td class="py-2.5 pr-3 text-white/90 whitespace-nowrap">{{ $col['title'] ?? '' }}</td>
                                <td class="py-2.5 pr-3 {{ $chip }} whitespace-nowrap">{{ $col['staff_status_label'] ?? '' }}</td>
                                <td class="py-2.5 text-white/70">
                                    <span class="text-white/90">{{ $col['client_card_label'] ?? '' }}</span>
                                    @if(! empty($col['client_reason']) && ($col['status'] ?? '') === 'not_sold')
                                        <span class="block mt-1 text-[11px] leading-snug text-white/45">{{ $col['client_reason'] }}</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        @if(! empty($home['bullets']) && is_array($home['bullets']))
            <ul class="mt-4 space-y-1.5 text-xs text-white/55 list-disc pl-4 leading-relaxed">
                @foreach($home['bullets'] as $bullet)
                    <li>{{ $bullet }}</li>
                @endforeach
            </ul>
        @endif

        <p class="mt-4 text-[11px] text-white/40 leading-relaxed">
            Staff cannot “view as client” in the portal. To see live numbers: open
            <a href="{{ route('admin.integration-health.index') }}" class="text-onit hover:text-white underline-offset-2 hover:underline">Integration Health</a>
            or sign in as a real client user. Product rules:
            <span class="text-white/50">Brain → UIOverhaul (not sold ≠ unprotected)</span>.
            Change sold toggles above, Save, then refresh this page.
        </p>
    </div>
@endif
