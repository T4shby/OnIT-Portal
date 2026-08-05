@php
    $freshnessGroups = [
        'cadence' => [
            'title' => 'How often to pull',
            'blurb' => 'Minutes. Lower = fresher, more API load.',
        ],
        'presence' => [
            'title' => 'Who counts as online',
            'blurb' => 'Client Admin / Billing / Requester only.',
        ],
        'hours' => [
            'title' => 'Business hours',
            'blurb' => 'Idle mode switches on this window.',
        ],
    ];
@endphp

{{-- Side drawer (stays below sticky admin nav z-30 when closed; open uses z-40) --}}
<div
        x-show="settingsOpen"
        x-cloak
        class="fixed inset-0 z-40"
        role="dialog"
        aria-modal="true"
        aria-labelledby="freshness-drawer-title"
    >
        <div
            class="absolute inset-0 bg-black/60"
            x-on:click="settingsOpen = false"
            aria-hidden="true"
        ></div>

        <div
            class="absolute inset-y-0 right-0 flex h-[100dvh] w-full max-w-[20rem] flex-col border-l border-onit-border bg-onit-ink sm:max-w-[22rem]"
            x-on:click.stop
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="translate-x-full"
            x-transition:enter-end="translate-x-0"
            x-transition:leave="transition ease-in duration-150"
            x-transition:leave-start="translate-x-0"
            x-transition:leave-end="translate-x-full"
        >
            <div class="flex shrink-0 items-start justify-between gap-4 border-b border-onit-border px-6 py-5">
                <div class="min-w-0">
                    <p id="freshness-drawer-title" class="font-condensed text-sm font-bold uppercase tracking-[0.1em] text-white">
                        Refresh timing
                    </p>
                    <p class="mt-2 text-xs font-light leading-relaxed text-white/50">
                        All clients · database settings
                    </p>
                </div>
                <button
                    type="button"
                    class="touch-target flex h-9 w-9 shrink-0 items-center justify-center text-white/50 hover:text-white"
                    x-on:click="settingsOpen = false"
                    aria-label="Close settings"
                >
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>

            <form
                method="POST"
                action="{{ route('admin.integration-health.freshness.update') }}"
                class="flex min-h-0 flex-1 flex-col"
            >
                @csrf
                @method('PUT')

                <div
                    class="min-h-0 flex-1 overflow-y-auto overscroll-y-contain px-6 py-6"
                    style="-webkit-overflow-scrolling: touch;"
                >
                    @if(! $canEditFreshness)
                        <p class="mb-6 text-sm leading-relaxed text-amber-200/90">View only — Super Admins can save.</p>
                    @endif

                    <div class="space-y-8">
                        @foreach($freshnessGroups as $groupKey => $group)
                            <section class="space-y-5">
                                <div class="space-y-1.5">
                                    <h3 class="font-condensed text-xs font-bold uppercase tracking-[0.12em] text-onit">
                                        {{ $group['title'] }}
                                    </h3>
                                    <p class="text-xs font-light leading-relaxed text-white/45">
                                        {{ $group['blurb'] }}
                                    </p>
                                </div>

                                <div class="space-y-5">
                                    @foreach($freshnessMeta as $key => $meta)
                                        @continue(($meta['group'] ?? '') !== $groupKey)
                                        @php
                                            $field = str_replace('freshness.', '', $key);
                                            $value = old('freshness.'.$field, $freshnessSettings[$key] ?? $meta['default']);
                                            $inputType = ($meta['type'] ?? '') === 'number' ? 'number' : 'text';
                                            $isTz = $field === 'timezone';
                                        @endphp
                                        <div class="space-y-2">
                                            <label for="freshness_{{ $field }}" class="block text-xs font-condensed font-semibold uppercase tracking-[0.06em] text-white/55">
                                                {{ $meta['label'] }}
                                                @if(! empty($meta['suffix']))
                                                    <span class="font-normal normal-case tracking-normal text-white/30"> ({{ $meta['suffix'] }})</span>
                                                @endif
                                            </label>
                                            <input
                                                id="freshness_{{ $field }}"
                                                name="freshness[{{ $field }}]"
                                                type="{{ $inputType }}"
                                                @if($inputType === 'number') step="any" min="0.5" inputmode="decimal" @endif
                                                value="{{ $value }}"
                                                autocomplete="off"
                                                @disabled(! $canEditFreshness)
                                                class="block border border-white/15 bg-[#011926] px-3 py-2.5 text-sm font-light text-white outline-none transition-colors placeholder:text-white/35 focus:border-onit disabled:opacity-50 {{ $isTz ? 'w-full' : 'w-[7.5rem]' }}"
                                                style="color-scheme: dark;"
                                            >
                                            <p class="text-xs font-light leading-relaxed text-white/40">{{ $meta['help'] }}</p>
                                            @error('freshness.'.$field)
                                                <p class="text-sm leading-snug text-red-400">{{ $message }}</p>
                                            @enderror
                                        </div>
                                    @endforeach
                                </div>
                            </section>
                        @endforeach
                    </div>
                </div>

                @if($canEditFreshness)
                    <div class="shrink-0 border-t border-onit-border px-6 py-4">
                        <button type="submit" class="cta-btn w-full px-4 py-3 text-xs">
                            Save timing
                        </button>
                    </div>
                @endif
            </form>
        </div>
    </div>
