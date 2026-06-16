@props(['link'])

@php
$label = match($link->link_type?->value) {
    'superops_sso' => 'Open portal',
    'superops_embedded' => 'Open support',
    default => 'Sign in',
};
@endphp

<a href="{{ $link->resolved_url }}"
   @if($link->open_in_new_tab && ! $link->usesInternalRoute()) target="_blank" rel="noopener noreferrer" @endif
   class="portal-tile group min-h-[11rem]">
    <div class="flex flex-1 flex-col">
        <div class="flex items-start justify-between gap-3">
            <div class="portal-tile-icon shrink-0">
                @include('components.icons.' . ($link->icon ?? 'link'))
            </div>
            <span class="rounded-full bg-onit-light px-3 py-1 text-xs font-semibold text-onit sm:opacity-0 sm:transition-opacity sm:group-hover:opacity-100">
                {{ $label }}
            </span>
        </div>

        <h3 class="mt-4 sm:mt-5 text-lg sm:text-xl font-bold text-onit-ink">{{ $link->name }}</h3>

        @if($link->description)
            <p class="mt-2 flex-1 text-sm leading-relaxed text-slate-500 line-clamp-3">{{ $link->description }}</p>
        @endif

        <div class="mt-5 sm:mt-6 flex items-center gap-2 text-sm font-semibold text-onit">
            <span>{{ $label }}</span>
            <svg class="h-4 w-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3"/>
            </svg>
        </div>
    </div>
</a>
