@props(['link'])

<x-card class="hover:shadow-md hover:border-onit/40 transition-shadow group">
    <div class="flex items-start gap-4">
        <div class="w-12 h-12 bg-onit-light rounded-lg flex items-center justify-center flex-shrink-0 group-hover:bg-onit group-hover:text-white transition-colors">
            @include('components.icons.' . ($link->icon ?? 'link'))
        </div>
        <div class="flex-1 min-w-0">
            <h3 class="font-semibold text-onit-ink">{{ $link->name }}</h3>
            @if($link->description)
                <p class="text-sm text-slate-500 mt-1">{{ $link->description }}</p>
            @endif
            <a href="{{ $link->resolved_url }}"
               @if($link->open_in_new_tab && ! $link->usesInternalRoute()) target="_blank" rel="noopener noreferrer" @endif
               class="inline-flex items-center gap-1 mt-3 text-sm font-medium text-onit hover:text-onit-hover">
                {{ $link->link_type?->value === 'superops_sso' ? 'Open' : ($link->link_type?->value === 'superops_embedded' ? 'Open Support' : 'Sign in') }}
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
            </a>
        </div>
    </div>
</x-card>
