@props(['title' => null])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'Dashboard' }} - {{ config('app.name') }}</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700&display=swap" rel="stylesheet" />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>[x-cloak] { display: none !important; }</style>
</head>
<body class="font-sans text-slate-600">
    <div class="portal-shell">
        <header class="portal-header">
            <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="flex justify-between items-center h-16">
                    <div class="flex items-center gap-8">
                        <a href="{{ route('dashboard') }}" class="flex items-center gap-3 group">
                            <x-portal-logo size="sm" />
                            <div>
                                <span class="font-semibold text-white group-hover:text-onit-light transition-colors">On IT Portal</span>
                                <span class="hidden sm:block text-[11px] uppercase tracking-widest text-slate-400">Client hub</span>
                            </div>
                        </a>
                        <nav class="hidden sm:flex gap-1">
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
                    </div>
                    <div class="flex items-center gap-4" x-data="{ open: false }">
                        <div class="hidden sm:block text-right">
                            <p class="text-sm font-medium text-white">{{ auth()->user()->name }}</p>
                            @if(auth()->user()->client)
                                <p class="text-xs text-slate-400">{{ auth()->user()->client->name }}</p>
                            @endif
                        </div>
                        <div class="relative">
                            <button @click="open = !open" class="flex h-10 w-10 items-center justify-center rounded-full bg-gradient-to-br from-onit to-onit-hover text-sm font-semibold text-white shadow-md shadow-onit/30 ring-2 ring-white/10 hover:ring-onit/40 transition-all">
                                {{ strtoupper(substr(auth()->user()->name, 0, 1)) }}
                            </button>
                            <div x-show="open" @click.away="open = false" x-cloak
                                 class="absolute right-0 mt-2 w-48 overflow-hidden rounded-xl border border-slate-200 bg-white py-1 shadow-xl z-50">
                                <form method="POST" action="{{ route('logout') }}">
                                    @csrf
                                    <button type="submit" class="block w-full px-4 py-2.5 text-left text-sm text-slate-700 hover:bg-onit-light/60 hover:text-onit-ink">Sign out</button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </header>

        <main class="flex-1">
            <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-8 sm:py-10">
                @if(session('success'))<x-alert type="success" class="mb-6">{{ session('success') }}</x-alert>@endif
                @if(session('error'))<x-alert type="danger" class="mb-6">{{ session('error') }}</x-alert>@endif
                {{ $slot }}
            </div>
        </main>

        <footer class="mt-auto border-t border-onit-ink/10 bg-onit-ink py-8">
            <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 flex flex-col sm:flex-row items-center justify-between gap-3 text-sm text-slate-400">
                <p>&copy; {{ date('Y') }} On IT Technology Partners</p>
                <p class="text-onit font-medium">Simplicity &amp; Value</p>
            </div>
        </footer>
    </div>
</body>
</html>
