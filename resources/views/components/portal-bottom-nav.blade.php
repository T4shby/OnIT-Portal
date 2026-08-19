@php
    $supportActive = request()->routeIs('client-admin.dashboard') || request()->routeIs('client-admin.live');

    $third = null;
    if (auth()->user()->can('view-organisation-wide')) {
        $third = [
            'href' => route('reports.index'),
            'label' => 'Reports',
            'active' => request()->routeIs('reports.*'),
            'icon' => 'reports',
        ];
    } elseif (auth()->user()->can('view-huntress-security')) {
        $third = [
            'href' => route('security.huntress.index'),
            'label' => 'Security',
            'active' => request()->routeIs('security.huntress.*'),
            'icon' => 'security',
        ];
    } elseif (auth()->user()->can('view-m365-directory')) {
        $third = [
            'href' => route('microsoft-365.directory'),
            'label' => 'M365',
            'active' => request()->routeIs('microsoft-365.*'),
            'icon' => 'm365',
        ];
    }
@endphp

<nav class="portal-bottom-nav safe-bottom sm:hidden" aria-label="Primary navigation">
    <a href="{{ route('dashboard') }}"
       class="portal-bottom-nav__item {{ request()->routeIs('dashboard') ? 'is-active' : '' }}">
        <svg class="portal-bottom-nav__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M3 10.5L12 3l9 7.5M5 9.75V20a1 1 0 001 1h4v-6h4v6h4a1 1 0 001-1V9.75"/>
        </svg>
        <span>Home</span>
    </a>

    @if(auth()->user()->can('view-client-admin-dashboard'))
        <a href="{{ route('client-admin.dashboard') }}"
           class="portal-bottom-nav__item {{ $supportActive ? 'is-active' : '' }}">
            <svg class="portal-bottom-nav__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
            </svg>
            <span>Support</span>
        </a>
    @endif

    @if($third)
        <a href="{{ $third['href'] }}"
           class="portal-bottom-nav__item {{ $third['active'] ? 'is-active' : '' }}">
            @if($third['icon'] === 'reports')
                <svg class="portal-bottom-nav__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 17v-6h4v6M5 21h14a2 2 0 002-2V7l-6-4H5a2 2 0 00-2 2v14a2 2 0 002 2z"/>
                </svg>
            @elseif($third['icon'] === 'security')
                <svg class="portal-bottom-nav__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
                </svg>
            @else
                <svg class="portal-bottom-nav__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M17.5 19H9a7 7 0 110-14h1.79a4.5 4.5 0 110 9z"/>
                </svg>
            @endif
            <span>{{ $third['label'] }}</span>
        </a>
    @endif

    <button type="button"
            class="portal-bottom-nav__item"
            @click="menuOpen = !menuOpen; userOpen = false"
            :class="menuOpen ? 'is-active' : ''"
            :aria-expanded="menuOpen"
            aria-label="More navigation">
        <svg class="portal-bottom-nav__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M4 7h16M4 12h16M4 17h16"/>
        </svg>
        <span>More</span>
    </button>
</nav>
