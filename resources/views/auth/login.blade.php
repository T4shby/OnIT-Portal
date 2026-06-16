<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sign In - {{ config('app.name') }}</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700&display=swap" rel="stylesheet" />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="font-sans antialiased">
    <div class="login-panel">
        <div class="absolute inset-0 opacity-30 pointer-events-none" style="background-image: radial-gradient(rgba(255,255,255,0.08) 1px, transparent 1px); background-size: 24px 24px;"></div>

        <div class="relative mx-auto flex min-h-screen max-w-6xl flex-col justify-center px-4 py-12 lg:flex-row lg:items-center lg:gap-16 lg:px-8">
            <div class="mb-10 max-w-xl lg:mb-0">
                <x-portal-logo size="lg" class="mb-6" />
                <p class="portal-section-title text-onit">On IT Technology Partners</p>
                <h1 class="mt-3 text-4xl font-bold tracking-tight sm:text-5xl">Your IT hub.<br>One sign-in.</h1>
                <p class="mt-5 text-lg leading-relaxed text-slate-300">
                    Access support, licensing, and the tools your organisation relies on — securely, from one place.
                </p>
                <div class="mt-8 hidden gap-4 sm:flex">
                    <div class="rounded-xl border border-white/10 bg-white/5 px-4 py-3 backdrop-blur-sm">
                        <p class="text-xs uppercase tracking-wider text-onit">Support</p>
                        <p class="mt-1 text-sm text-slate-200">SuperOps requester portal</p>
                    </div>
                    <div class="rounded-xl border border-white/10 bg-white/5 px-4 py-3 backdrop-blur-sm">
                        <p class="text-xs uppercase tracking-wider text-onit">Licensing</p>
                        <p class="mt-1 text-sm text-slate-200">Pax8 &amp; Microsoft 365</p>
                    </div>
                </div>
            </div>

            <div class="w-full max-w-md">
                <div class="login-card">
                    <h2 class="text-xl font-semibold text-onit-ink">Sign in</h2>
                    <p class="mt-1 text-sm text-slate-500">Use your work Microsoft account to continue.</p>

                    @if(session('error'))
                        <x-alert type="danger" class="mt-6">{{ session('error') }}</x-alert>
                    @endif

                    @if(!config('services.azure.client_id'))
                        <x-alert type="warning" class="mt-6">
                            Microsoft Entra ID is not configured yet. Add your app registration credentials to <code class="text-xs">.env</code> before signing in.
                        </x-alert>
                    @endif

                    <a href="{{ route('auth.microsoft') }}" class="portal-btn-primary mt-8 w-full">
                        <svg class="h-5 w-5" viewBox="0 0 21 21" fill="none" aria-hidden="true">
                            <rect x="1" y="1" width="9" height="9" fill="#F25022"/>
                            <rect x="11" y="1" width="9" height="9" fill="#7FBA00"/>
                            <rect x="1" y="11" width="9" height="9" fill="#00A4EF"/>
                            <rect x="11" y="11" width="9" height="9" fill="#FFB900"/>
                        </svg>
                        Sign in with Microsoft
                    </a>

                    <p class="mt-6 text-center text-xs text-slate-400">
                        Managed by On IT &middot; Secure single sign-on
                    </p>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
