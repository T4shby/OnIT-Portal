@props(['mobile' => false])

@php
    $user = auth()->user();
    $hasSupport = $user?->can('view-client-admin-dashboard');
    $hasSecurity = $user?->can('view-huntress-security');
    $hasM365 = $user?->can('view-m365-directory');
    $showServices = $hasSupport || $hasSecurity || $hasM365;
    $servicesActive = request()->routeIs('client-admin.*')
        || request()->routeIs('security.*')
        || request()->routeIs('microsoft-365.*');
    $linkClass = $mobile
        ? 'portal-nav-link touch-target py-3'
        : 'portal-nav-link';
@endphp

@if($showServices)
    @if($mobile)
        <p class="portal-body-muted text-[11px] uppercase tracking-[0.14em] mt-2 mb-1 px-0">Services</p>
        @if($hasSecurity)
            <a href="{{ route('security.huntress.index') }}"
               @click="menuOpen = false"
               class="{{ $linkClass }} {{ request()->routeIs('security.*') ? 'portal-nav-link-active' : '' }}">
                Security
            </a>
        @endif
        @if($hasM365)
            <a href="{{ route('microsoft-365.directory') }}"
               @click="menuOpen = false"
               class="{{ $linkClass }} {{ request()->routeIs('microsoft-365.*') ? 'portal-nav-link-active' : '' }}">
                Microsoft 365
            </a>
        @endif
        @if($hasSupport)
            <a href="{{ route('client-admin.dashboard') }}"
               @click="menuOpen = false"
               class="{{ $linkClass }} {{ request()->routeIs('client-admin.dashboard') || request()->routeIs('client-admin.live') ? 'portal-nav-link-active' : '' }}">
                Support &amp; Devices
            </a>
        @endif
    @else
        <div class="relative" @click.away="servicesOpen = false">
            <button type="button"
                    class="portal-nav-link inline-flex items-center gap-1 {{ $servicesActive ? 'portal-nav-link-active' : '' }}"
                    @click="servicesOpen = !servicesOpen; userOpen = false"
                    :aria-expanded="servicesOpen">
                Services
                <svg class="h-3.5 w-3.5 opacity-70" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                    <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 10.94l3.71-3.71a.75.75 0 111.06 1.06l-4.24 4.24a.75.75 0 01-1.06 0L5.21 8.29a.75.75 0 01.02-1.08z" clip-rule="evenodd"/>
                </svg>
            </button>
            <div x-show="servicesOpen" x-cloak class="portal-nav-drop">
                @if($hasSecurity)
                    <a href="{{ route('security.huntress.index') }}"
                       class="portal-nav-drop__item {{ request()->routeIs('security.*') ? 'is-active' : '' }}">
                        Security
                    </a>
                @endif
                @if($hasM365)
                    <a href="{{ route('microsoft-365.directory') }}"
                       class="portal-nav-drop__item {{ request()->routeIs('microsoft-365.*') ? 'is-active' : '' }}">
                        Microsoft 365
                    </a>
                @endif
                @if($hasSupport)
                    <a href="{{ route('client-admin.dashboard') }}"
                       class="portal-nav-drop__item {{ request()->routeIs('client-admin.dashboard') || request()->routeIs('client-admin.live') ? 'is-active' : '' }}">
                        Support &amp; Devices
                    </a>
                @endif
            </div>
        </div>
    @endif
@endif
