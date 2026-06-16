@props(['title' => null])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'Admin' }} - {{ config('app.name') }}</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=dm-sans:400,500,600,700&display=swap" rel="stylesheet" />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>[x-cloak] { display: none !important; }</style>
</head>
<body class="font-sans antialiased bg-slate-50 text-slate-600">
    <div class="min-h-screen flex" x-data="{ sidebarOpen: false }">
        <aside class="hidden lg:flex lg:flex-col lg:w-64 bg-onit-ink text-white">
            <div class="p-6">
                <a href="{{ route('admin.dashboard') }}" class="flex items-center gap-2">
                    <div class="w-8 h-8 bg-onit rounded-lg flex items-center justify-center">
                        <span class="text-white font-bold text-sm">IT</span>
                    </div>
                    <span class="font-semibold">Admin</span>
                </a>
            </div>
            <nav class="flex-1 px-4 space-y-1">@include('admin.partials.nav')</nav>
            <div class="p-4 border-t border-onit/20">
                <a href="{{ route('dashboard') }}" class="text-sm text-slate-400 hover:text-white">← Back to Portal</a>
            </div>
        </aside>
        <div class="flex-1 flex flex-col">
            <header class="bg-white border-b border-slate-200 lg:hidden">
                <div class="flex items-center justify-between px-4 h-16">
                    <button @click="sidebarOpen = !sidebarOpen" class="text-slate-600">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
                    </button>
                    <span class="font-semibold text-slate-900">Admin</span>
                </div>
            </header>
            <div x-show="sidebarOpen" x-cloak class="lg:hidden fixed inset-0 z-50">
                <div class="fixed inset-0 bg-black/50" @click="sidebarOpen = false"></div>
                <aside class="fixed inset-y-0 left-0 w-64 bg-onit-ink text-white p-4">
                    <nav class="space-y-1 mt-8">@include('admin.partials.nav')</nav>
                </aside>
            </div>
            <main class="flex-1 p-6 lg:p-8">
                @if(session('success'))<x-alert type="success" class="mb-6">{{ session('success') }}</x-alert>@endif
                {{ $slot }}
            </main>
        </div>
    </div>
</body>
</html>
