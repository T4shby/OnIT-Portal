@props(['link'])

<a href="{{ $link->resolved_url }}"
   @if($link->open_in_new_tab && ! $link->usesInternalRoute()) target="_blank" rel="noopener noreferrer" @endif
   class="portal-tile group">
    <div class="portal-tile-icon">
        @include('components.icons.' . ($link->icon ?? 'link'))
    </div>

    <div class="min-w-0 flex-1 pt-0.5">
        <h3 class="text-base font-semibold text-onit-ink group-hover:text-onit sm:text-lg">{{ $link->name }}</h3>

        @if($link->description)
            <p class="mt-1.5 text-sm leading-relaxed text-slate-500">{{ $link->description }}</p>
        @endif
    </div>
</a>
