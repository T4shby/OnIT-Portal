@props(['title' => null])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#011926">
    <title>{{ $title ?? 'Dashboard' }} - {{ config('app.name') }}</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700&display=swap" rel="stylesheet" />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>[x-cloak] { display: none !important; }</style>
</head>
<body class="font-sans text-slate-600">
    <div class="portal-shell" x-data="{ menuOpen: false, userOpen: false }" @keydown.escape.window="menuOpen = false; userOpen = false">
        <header class="portal-header safe-top">
            <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="flex justify-between items-center min-h-[4rem] py-2 gap-3">
                    <a href="{{ route('dashboard') }}" class="flex items-center gap-2.5 min-w-0 group shrink">
                        <x-portal-logo size="sm" />
                        <div class="min-w-0">
                            <span class="block font-semibold text-white truncate group-hover:text-onit-light transition-colors">On IT Portal</span>
                            <span class="hidden sm:block text-[10px] uppercase tracking-widest text-slate-400">Client hub</span>
                        </div>
                    </a>

                    <nav class="hidden sm:flex items-center gap-1">
                        <a href="{{ route('dashboard') }}"
                           class="rounded-lg px-3 py-2 text-sm font-medium transition-colors {{ request()->routeIs('dashboard') ? 'bg-onit/15 text-onit' : 'text-slate-300 hover:bg-white/5 hover:text-white' }}">
                            Dashboard
                        </a>
                        @can('access-admin')
                            <a href="{{ route('admin.dashboard') }}"
                               class="rounded-lg px-3 py-2 text-sm font-medium transition-colors {{ request()->routeIs('admin.*') ? 'bg-onit/15 text-onit' : 'text-slate-300 hover:bg-white/5 hover:text-white' }}">
                                Admin
                            </a>
                        @endcan
                    </nav>

                    <div class="flex items-center gap-2 sm:gap-3 shrink-0">
                        <button type="button"
                                @click="menuOpen = !menuOpen; userOpen = false"
                                class="sm:hidden inline-flex h-11 w-11 items-center justify-center rounded-xl border border-white/10 bg-white/5 text-white touch-target"
                                aria-label="Open menu"
                                :aria-expanded="menuOpen">
                            <svg x-show="!menuOpen" class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
                            </svg>
                            <svg x-show="menuOpen" x-cloak class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                            </svg>
                        </button>

                        <div class="hidden sm:block text-right max-w-[12rem]">
                            <p class="text-sm font-medium text-white truncate">{{ auth()->user()->name }}</p>
                            @if(auth()->user()->client)
                                <p class="text-xs text-slate-400 truncate">{{ auth()->user()->client->name }}</p>
                            @endif
                        </div>

                        <div class="relative">
                            <button type="button"
                                    @click="userOpen = !userOpen; menuOpen = false"
                                    class="flex h-11 w-11 items-center justify-center rounded-full bg-gradient-to-br from-onit to-onit-hover text-sm font-semibold text-white shadow-md shadow-onit/30 ring-2 ring-white/10 touch-target"
                                    aria-label="Account menu"
                                    :aria-expanded="userOpen">
                                {{ strtoupper(substr(auth()->user()->name, 0, 1)) }}
                            </button>
                            <div x-show="userOpen" @click.away="userOpen = false" x-cloak
                                 class="absolute right-0 mt-2 w-56 overflow-hidden rounded-xl border border-slate-200 bg-white py-1 shadow-xl z-50">
                                <div class="border-b border-slate-100 px-4 py-3 sm:hidden">
                                    <p class="text-sm font-medium text-onit-ink truncate">{{ auth()->user()->name }}</p>
                                    @if(auth()->user()->client)
                                        <p class="text-xs text-slate-500 truncate">{{ auth()->user()->client->name }}</p>
                                    @endif
                                </div>
                                <form method="POST" action="{{ route('logout') }}">
                                    @csrf
                                    <button type="submit" class="block w-full px-4 py-3 text-left text-sm text-slate-700 hover:bg-onit-light/60 hover:text-onit-ink touch-target">Sign out</button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>

                <nav x-show="menuOpen"
                     x-cloak
                     x-transition:enter="transition ease-out duration-200"
                     x-transition:enter-start="opacity-0 -translate-y-2"
                     x-transition:enter-end="opacity-100 translate-y-0"
                     x-transition:leave="transition ease-in duration-150"
                     x-transition:leave-start="opacity-100 translate-y-0"
                     x-transition:leave-end="opacity-0 -translate-y-2"
                     class="sm:hidden border-t border-white/10 pb-4 pt-3 space-y-1">
                    <a href="{{ route('dashboard') }}"
                       @click="menuOpen = false"
                       class="flex items-center rounded-xl px-4 py-3 text-sm font-medium touch-target {{ request()->routeIs('dashboard') ? 'bg-onit/15 text-onit' : 'text-slate-200 hover:bg-white/5' }}">
                        Dashboard
                    </a>
                    @can('access-admin')
                        <a href="{{ route('admin.dashboard') }}"
                           @click="menuOpen = false"
                           class="flex items-center rounded-xl px-4 py-3 text-sm font-medium touch-target {{ request()->routeIs('admin.*') ? 'bg-onit/15 text-onit' : 'text-slate-200 hover:bg-white/5' }}">
                            Admin
                        </a>
                    @endcan
                </nav>
            </div>
        </header>

        <main class="flex-1">
            <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-6 sm:py-10 safe-bottom">
                @if(session('success'))<x-alert type="success" class="mb-6">{{ session('success') }}</x-alert>@endif
                @if(session('error'))<x-alert type="danger" class="mb-6">{{ session('error') }}</x-alert>@endif
                {{ $slot }}
            </div>
        </main>

        <footer class="mt-auto border-t border-onit-ink/10 bg-onit-ink py-6 sm:py-8 safe-bottom">
            <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 flex flex-col sm:flex-row items-center justify-between gap-3 text-sm text-slate-400 text-center sm:text-left">
                <p>&copy; {{ date('Y') }} On IT Technology Partners</p>
                <p class="text-onit font-medium">Simplicity &amp; Value</p>
            </div>
        </footer>
    </div>
</body>
</html>
