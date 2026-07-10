@props(['title' => null])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#011926">
    <title>{{ $title ?? 'Admin' }} - {{ config('app.name') }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@600;700;800;900&family=Barlow:wght@300;400;500;600&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>[x-cloak] { display: none !important; }</style>
</head>
<body>
    <div class="admin-shell flex min-h-screen" x-data="{ sidebarOpen: false }" @keydown.escape.window="sidebarOpen = false">
        <div class="grid-overlay" aria-hidden="true"></div>

        <aside class="relative z-[2] hidden lg:flex lg:w-64 lg:flex-col border-r border-onit-border bg-onit-ink">
            <div class="p-6">
                <a href="{{ route('admin.dashboard') }}" class="flex items-center gap-3">
                    <x-portal-logo size="sm" />
                    <span class="font-condensed font-bold uppercase tracking-wide text-white">Admin</span>
                </a>
            </div>
            <nav class="flex-1 px-4 space-y-1">@include('admin.partials.nav')</nav>
            <div class="border-t border-onit-border p-4">
                <a href="{{ route('dashboard') }}" class="portal-body-muted text-sm hover:text-onit">&larr; Back to portal</a>
            </div>
        </aside>

        <div class="relative z-[1] flex min-w-0 flex-1 flex-col">
            <header class="safe-top border-b border-onit-border bg-onit-ink lg:hidden">
                <div class="flex h-16 items-center justify-between px-4">
                    <button type="button" @click="sidebarOpen = !sidebarOpen" class="touch-target text-white/80" aria-label="Open menu">
                        <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
                    </button>
                    <span class="font-condensed font-bold uppercase text-white">Admin</span>
                </div>
            </header>

            <div x-show="sidebarOpen" x-cloak class="fixed inset-0 z-50 lg:hidden">
                <div class="fixed inset-0 bg-black/60" @click="sidebarOpen = false" aria-hidden="true"></div>
                <aside class="fixed inset-y-0 left-0 flex w-64 max-w-[85vw] flex-col border-r border-onit-border bg-onit-ink p-4 safe-top safe-bottom">
                    <div class="mb-4 flex items-center justify-between gap-2">
                        <span class="font-condensed text-sm font-bold uppercase tracking-wide text-white">Admin menu</span>
                        <button type="button" @click="sidebarOpen = false" class="touch-target text-white/80" aria-label="Close menu">&times;</button>
                    </div>
                    <nav class="flex-1 space-y-1 overflow-y-auto">@include('admin.partials.nav')</nav>
                    <div class="border-t border-onit-border pt-4">
                        <a href="{{ route('dashboard') }}" class="portal-body-muted text-sm hover:text-onit">&larr; Back to portal</a>
                    </div>
                </aside>
            </div>

            <main class="admin-main">
                @if(session('success'))<x-alert type="success" class="mb-6">{{ session('success') }}</x-alert>@endif
                @if(session('warning'))<x-alert type="warning" class="mb-6">{{ session('warning') }}</x-alert>@endif
                @if(session('error'))<x-alert type="danger" class="mb-6">{{ session('error') }}</x-alert>@endif
                {{ $slot }}
            </main>
        </div>
    </div>
</body>
</html>
