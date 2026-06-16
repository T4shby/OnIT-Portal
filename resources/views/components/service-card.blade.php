@props(['link'])

<a href="{{ $link->resolved_url }}"
   @if($link->open_in_new_tab && ! $link->usesInternalRoute()) target="_blank" rel="noopener noreferrer" @endif
   class="portal-link">
    <x-portal-link-brand :link="$link" />
</a>
