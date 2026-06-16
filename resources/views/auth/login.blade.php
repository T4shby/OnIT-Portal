<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#011926">
    <title>Sign In - {{ config('app.name') }}</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=dm-sans:400,500,600,700&display=swap" rel="stylesheet" />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    <div class="login-panel safe-top safe-bottom">
        <div class="mx-auto flex min-h-[100dvh] max-w-5xl flex-col justify-center px-4 py-8 lg:flex-row lg:items-center lg:gap-20 lg:px-8">
            <div class="order-1 w-full max-w-md mx-auto lg:order-2 lg:mx-0">
                <div class="login-card">
                    <div class="flex items-center gap-3 mb-6 lg:hidden">
                        <x-portal-logo size="sm" />
                        <p class="font-semibold text-onit-ink">On IT Portal</p>
                    </div>

                    <h2 class="text-lg font-semibold text-onit-ink">Sign in</h2>
                    <p class="mt-1 text-sm text-slate-500">Use your work Microsoft account.</p>

                    @if(session('error'))
                        <x-alert type="danger" class="mt-6">{{ session('error') }}</x-alert>
                    @endif

                    @if(!config('services.azure.client_id'))
                        <x-alert type="warning" class="mt-6">
                            Microsoft Entra ID is not configured yet. Add your app registration credentials to <code class="text-xs">.env</code> before signing in.
                        </x-alert>
                    @endif

                    <a href="{{ route('auth.microsoft') }}" class="portal-btn-secondary mt-6 sm:mt-8 w-full border-onit-ink hover:bg-onit-ink hover:text-white">
                        <svg class="h-4 w-4 shrink-0" viewBox="0 0 21 21" fill="none" aria-hidden="true">
                            <rect x="1" y="1" width="9" height="9" fill="#F25022"/>
                            <rect x="11" y="1" width="9" height="9" fill="#7FBA00"/>
                            <rect x="1" y="11" width="9" height="9" fill="#00A4EF"/>
                            <rect x="11" y="11" width="9" height="9" fill="#FFB900"/>
                        </svg>
                        Sign in with Microsoft
                    </a>
                </div>
            </div>

            <div class="order-2 mt-10 max-w-lg lg:order-1 lg:mt-0">
                <x-portal-logo size="lg" class="mb-6 hidden lg:inline-flex" />
                <h1 class="text-2xl font-semibold sm:text-3xl lg:text-4xl text-white">On IT Portal</h1>
                <p class="mt-4 text-sm sm:text-base text-slate-400">
                    Sign in with your organisation Microsoft account.
                </p>
            </div>
        </div>
    </div>
</body>
</html>
