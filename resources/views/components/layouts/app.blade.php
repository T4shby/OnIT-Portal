@props(['title' => null, 'contentClass' => 'max-w-portal'])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#011926">
    <title>{{ $title ?? 'Dashboard' }} - {{ config('app.name') }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@600;700;800;900&family=Barlow:wght@300;400;500;600&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>[x-cloak] { display: none !important; }</style>
</head>
<body>
    <div class="portal-shell" x-data="{ menuOpen: false, userOpen: false }" @keydown.escape.window="menuOpen = false; userOpen = false">
        <div class="grid-overlay" aria-hidden="true"></div>

        <header class="portal-header safe-top">
            <div class="max-w-portal mx-auto px-4 sm:px-6 lg:px-8">
                <div class="flex items-center justify-between min-h-[4rem] py-4 gap-4">
                    <a href="{{ route('dashboard') }}" class="flex items-center gap-3 min-w-0 shrink">
                        <x-portal-logo size="sm" />
                        <span class="font-condensed font-bold uppercase tracking-wide text-white truncate hidden sm:inline">On IT Portal</span>
                    </a>

                    <nav class="hidden sm:flex items-center gap-8">
                        <a href="{{ route('dashboard') }}"
                           class="portal-nav-link {{ request()->routeIs('dashboard') ? 'portal-nav-link-active' : '' }}">
                            Dashboard
                        </a>
                        @can('view-client-admin-dashboard')
                            <a href="{{ route('client-admin.dashboard') }}"
                               class="portal-nav-link {{ request()->routeIs('client-admin.*') ? 'portal-nav-link-active' : '' }}">
                                Admin
                            </a>
                        @endcan
                        @can('view-m365-directory')
                            <a href="{{ route('microsoft-365.directory') }}"
                               class="portal-nav-link {{ request()->routeIs('microsoft-365.*') ? 'portal-nav-link-active' : '' }}">
                                Microsoft 365
                            </a>
                        @endcan
                        @can('access-admin')
                            <a href="{{ route('admin.dashboard') }}"
                               class="portal-nav-link {{ request()->routeIs('admin.*') ? 'portal-nav-link-active' : '' }}">
                                Admin
                            </a>
                        @endcan
                    </nav>

                    <div class="flex items-center gap-3 shrink-0">
                        <button type="button"
                                @click="menuOpen = !menuOpen; userOpen = false"
                                class="sm:hidden portal-nav-link touch-target px-2"
                                aria-label="Open menu"
                                :aria-expanded="menuOpen">
                            <span x-text="menuOpen ? 'Close' : 'Menu'"></span>
                        </button>

                        <span class="hidden md:inline portal-body-muted text-xs truncate max-w-[10rem]">{{ auth()->user()->name }}</span>

                        <div class="relative">
                            <button type="button"
                                    @click="userOpen = !userOpen; menuOpen = false"
                                    class="portal-avatar"
                                    aria-label="Account menu"
                                    :aria-expanded="userOpen">
                                {{ strtoupper(substr(auth()->user()->name, 0, 1)) }}
                            </button>
                            <div x-show="userOpen" @click.away="userOpen = false" x-cloak class="portal-user-menu">
                                <div class="border-b border-white/10 px-4 py-3 md:hidden">
                                    <p class="portal-card-title text-sm truncate">{{ auth()->user()->name }}</p>
                                    @if(auth()->user()->client)
                                        <p class="portal-body-muted text-xs truncate mt-1">{{ auth()->user()->client->name }}</p>
                                    @endif
                                </div>
                                <form method="POST" action="{{ route('logout') }}">
                                    @csrf
                                    <button type="submit" class="block w-full px-4 py-3 text-left text-sm font-light text-white/80 hover:text-onit touch-target">Sign out</button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>

                <nav x-show="menuOpen"
                     x-cloak
                     x-transition
                     class="sm:hidden border-t border-white/10 pb-4 pt-3 flex flex-col gap-3">
                    <a href="{{ route('dashboard') }}"
                       @click="menuOpen = false"
                       class="portal-nav-link touch-target {{ request()->routeIs('dashboard') ? 'portal-nav-link-active' : '' }}">
                        Dashboard
                    </a>
                    @can('view-client-admin-dashboard')
                        <a href="{{ route('client-admin.dashboard') }}"
                           @click="menuOpen = false"
                           class="portal-nav-link touch-target {{ request()->routeIs('client-admin.*') ? 'portal-nav-link-active' : '' }}">
                            Admin
                        </a>
                    @endcan
                    @can('view-m365-directory')
                        <a href="{{ route('microsoft-365.directory') }}"
                           @click="menuOpen = false"
                           class="portal-nav-link touch-target {{ request()->routeIs('microsoft-365.*') ? 'portal-nav-link-active' : '' }}">
                            Microsoft 365
                        </a>
                    @endcan
                    @can('access-admin')
                        <a href="{{ route('admin.dashboard') }}"
                           @click="menuOpen = false"
                           class="portal-nav-link touch-target {{ request()->routeIs('admin.*') ? 'portal-nav-link-active' : '' }}">
                            Admin
                        </a>
                    @endcan
                </nav>
            </div>
        </header>

        <main class="flex-1 relative z-[1]">
            <div class="{{ $contentClass }} mx-auto px-4 sm:px-6 lg:px-8 py-8 sm:py-12 safe-bottom">
                @if(session('success'))<x-alert type="success" class="mb-6">{{ session('success') }}</x-alert>@endif
                @if(session('error'))<x-alert type="danger" class="mb-6">{{ session('error') }}</x-alert>@endif
                {{ $slot }}
            </div>
        </main>

        <footer class="relative z-[1] mt-auto border-t border-onit-border py-8 safe-bottom">
            <div class="max-w-portal mx-auto px-4 sm:px-6 lg:px-8 flex flex-col sm:flex-row items-center justify-between gap-2 portal-body-muted text-xs text-center sm:text-left">
                <p>&copy; {{ date('Y') }} On IT Technology Partners</p>
                <p class="text-onit font-condensed font-bold uppercase tracking-wide">Simplicity &amp; Value</p>
            </div>
        </footer>
    </div>
</body>
</html>
