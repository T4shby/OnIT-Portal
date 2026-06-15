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
</head>
<body class="font-sans antialiased bg-slate-50 text-slate-600">
    <div class="min-h-screen flex flex-col">
        <header class="bg-white border-b border-slate-200">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="flex justify-between items-center h-16">
                    <div class="flex items-center gap-8">
                        <a href="{{ route('dashboard') }}" class="flex items-center gap-2">
                            <div class="w-8 h-8 bg-onit rounded-lg flex items-center justify-center">
                                <span class="text-white font-bold text-sm">IT</span>
                            </div>
                            <span class="font-semibold text-slate-900">On IT Portal</span>
                        </a>
                        <nav class="hidden sm:flex gap-6">
                            <a href="{{ route('dashboard') }}" class="text-sm font-medium {{ request()->routeIs('dashboard') ? 'text-onit' : 'text-slate-600 hover:text-slate-900' }}">Dashboard</a>
                            @can('access-admin')
                                <a href="{{ route('admin.dashboard') }}" class="text-sm font-medium {{ request()->routeIs('admin.*') ? 'text-onit' : 'text-slate-600 hover:text-slate-900' }}">
                                    Admin
                                </a>
                            @endcan
                        </nav>
                    </div>
                    <div class="flex items-center gap-4" x-data="{ open: false }">
                        <span class="text-sm text-slate-600 hidden sm:block">{{ auth()->user()->name }}</span>
                        <div class="relative">
                            <button @click="open = !open" class="flex items-center gap-2 text-sm text-slate-700 hover:text-slate-900">
                                <div class="w-8 h-8 bg-onit-light rounded-full flex items-center justify-center">
                                    <span class="text-onit font-medium text-sm">{{ strtoupper(substr(auth()->user()->name, 0, 1)) }}</span>
                                </div>
                            </button>
                            <div x-show="open" @click.away="open = false" x-cloak class="absolute right-0 mt-2 w-48 bg-white rounded-lg shadow-lg border border-slate-200 py-1 z-50">
                                <form method="POST" action="{{ route('logout') }}">
                                    @csrf
                                    <button type="submit" class="block w-full text-left px-4 py-2 text-sm text-slate-700 hover:bg-slate-50">Sign out</button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </header>

        <main class="flex-1">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
                @if(session('success'))
                    <x-alert type="success" class="mb-6">{{ session('success') }}</x-alert>
                @endif
                @if(session('error'))
                    <x-alert type="danger" class="mb-6">{{ session('error') }}</x-alert>
                @endif

                {{ $slot }}
            </div>
        </main>

        <footer class="bg-white border-t border-slate-200 py-6">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 text-center text-sm text-slate-500">
                &copy; {{ date('Y') }} On IT Technology Partners. All rights reserved.
            </div>
        </footer>
    </div>
</body>
</html>
