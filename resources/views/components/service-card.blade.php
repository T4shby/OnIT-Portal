@props(['link'])

<a href="{{ $link->resolved_url }}"
   @if($link->open_in_new_tab && ! $link->usesInternalRoute()) target="_blank" rel="noopener noreferrer" @endif
   class="portal-tile group">
    <div class="portal-tile-icon">
        @include('components.icons.' . ($link->icon ?? 'link'))
    </div>

    <h3 class="mt-4 text-base font-semibold text-onit-ink group-hover:text-onit">{{ $link->name }}</h3>

    @if($link->description)
        <p class="mt-2 text-sm leading-relaxed text-slate-500">{{ $link->description }}</p>
    @endif
</a>

