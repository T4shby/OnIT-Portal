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
    <style>
        [x-cloak] { display: none !important; }
        .portal-beta-banner {
            position: relative;
            z-index: 2;
            border-bottom: 1px solid rgba(255, 112, 0, 0.35);
            background: rgba(255, 112, 0, 0.1);
            padding: 8px 16px;
            text-align: center;
        }
        .portal-beta-banner-text {
            margin: 0 auto;
            max-width: 56rem;
            font-size: 13px;
            line-height: 1.45;
            color: rgba(255, 255, 255, 0.82);
            text-align: center;
        }
        .portal-beta-pill {
            display: inline-block;
            margin-right: 8px;
            padding: 2px 8px;
            border-radius: 999px;
            background: #FF7000;
            color: #0a0f14;
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            vertical-align: middle;
        }
        .portal-beta-link {
            color: #FF7000;
            font-weight: 600;
            text-decoration: underline;
            text-underline-offset: 2px;
        }
        .portal-beta-link:hover { color: #ff8a33; }
    </style>
</head>
<body>
    <div class="portal-shell" x-data="{ menuOpen: false, userOpen: false, servicesOpen: false }" @keydown.escape.window="menuOpen = false; userOpen = false; servicesOpen = false">
        <div class="grid-overlay" aria-hidden="true"></div>

        <div class="portal-beta-banner safe-top" role="status">
            <p class="portal-beta-banner-text">
                <span class="portal-beta-pill">Beta</span>
                This portal is still in beta and figures may not be quite right.
                If you notice something that does not look right, please
                @can('contact-support')
                    <a href="{{ route('contact-support.index') }}" class="portal-beta-link">contact the Service Desk</a>.
                @else
                    <a href="mailto:{{ config('onit_support.email') }}" class="portal-beta-link">email the Service Desk</a>.
                @endcan
            </p>
        </div>

        <header class="portal-header">
            <div class="max-w-[96rem] mx-auto px-4 sm:px-6 lg:px-8">
                <div class="flex items-center justify-between min-h-[3.5rem] py-3 sm:min-h-[4rem] sm:py-4 gap-4">
                    <a href="{{ route('dashboard') }}" class="flex items-center gap-3 min-w-0 shrink">
                        <x-portal-logo size="sm" />
                        <span class="font-condensed font-bold uppercase tracking-wide text-white truncate hidden sm:inline">On IT Portal</span>
                    </a>

                    <nav class="hidden sm:flex items-center gap-8">
                        <a href="{{ route('dashboard') }}"
                           class="portal-nav-link {{ request()->routeIs('dashboard') ? 'portal-nav-link-active' : '' }}">
                            Dashboard
                        </a>
                        @can('view-organisation-wide')
                            <a href="{{ route('reports.index') }}"
                               class="portal-nav-link {{ request()->routeIs('reports.*') ? 'portal-nav-link-active' : '' }}">
                                Reports
                            </a>
                        @endcan
                        <x-portal-services-nav />
                        @can('contact-support')
                            <a href="{{ route('contact-support.index') }}"
                               class="portal-nav-link {{ request()->routeIs('contact-support.*') ? 'portal-nav-link-active' : '' }}">
                                Contact Support
                            </a>
                        @endcan
                        @can('access-admin')
                            <a href="{{ route('admin.dashboard') }}"
                               class="portal-nav-link {{ request()->routeIs('admin.*') ? 'portal-nav-link-active' : '' }}">
                                Staff Admin
                            </a>
                        @endcan
                    </nav>

                    <div class="flex items-center gap-3 shrink-0">
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

            </div>
        </header>

        <div x-show="menuOpen" x-cloak class="sm:hidden">
            <div class="portal-mobile-menu__backdrop" @click="menuOpen = false" aria-hidden="true"></div>
            <nav class="portal-mobile-menu" aria-label="More navigation">
                <div class="mb-3 flex items-center justify-between gap-3 border-b border-white/10 pb-3">
                    <span class="portal-card-title text-sm">Menu</span>
                    <button type="button" @click="menuOpen = false" class="touch-target text-white/70" aria-label="Close menu">&times;</button>
                </div>
                <div class="flex flex-col gap-1">
                    <a href="{{ route('dashboard') }}"
                       @click="menuOpen = false"
                       class="portal-nav-link touch-target py-3 {{ request()->routeIs('dashboard') ? 'portal-nav-link-active' : '' }}">
                        Dashboard
                    </a>
                    @can('view-organisation-wide')
                        <a href="{{ route('reports.index') }}"
                           @click="menuOpen = false"
                           class="portal-nav-link touch-target py-3 {{ request()->routeIs('reports.*') ? 'portal-nav-link-active' : '' }}">
                            Reports
                        </a>
                    @endcan
                    <x-portal-services-nav :mobile="true" />
                    @can('contact-support')
                        <a href="{{ route('contact-support.index') }}"
                           @click="menuOpen = false"
                           class="portal-nav-link touch-target py-3 {{ request()->routeIs('contact-support.*') ? 'portal-nav-link-active' : '' }}">
                            Contact Support
                        </a>
                    @endcan
                    @can('access-admin')
                        <a href="{{ route('admin.dashboard') }}"
                           @click="menuOpen = false"
                           class="portal-nav-link touch-target py-3 {{ request()->routeIs('admin.*') ? 'portal-nav-link-active' : '' }}">
                            Staff Admin
                        </a>
                    @endcan
                </div>
            </nav>
        </div>

        <x-portal-bottom-nav />

        <main class="portal-main">
            <div class="{{ $contentClass }} portal-main-inner">
                @if(session('success'))<x-alert type="success" class="mb-6">{{ session('success') }}</x-alert>@endif
                @if(session('error'))<x-alert type="danger" class="mb-6">{{ session('error') }}</x-alert>@endif
                {{ $slot }}
            </div>
        </main>

        <footer class="portal-footer relative z-[1] mt-auto border-t border-onit-border py-8 safe-bottom">
            <div class="max-w-[96rem] mx-auto px-4 sm:px-6 lg:px-8 flex flex-col sm:flex-row items-center justify-between gap-2 portal-body-muted text-xs text-center sm:text-left">
                <p>&copy; {{ date('Y') }} On IT Technology Partners</p>
                <p class="text-onit font-condensed font-bold uppercase tracking-wide">Simplicity &amp; Value</p>
            </div>
        </footer>
    </div>
</body>
</html>
