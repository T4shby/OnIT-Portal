<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#011926">
    <title>Sign In - {{ config('app.name') }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@600;700;800;900&family=Barlow:wght@300;400;500;600&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    <div class="login-panel safe-top safe-bottom">
        <div class="grid-overlay" aria-hidden="true"></div>
        <div class="relative z-[1] mx-auto flex min-h-[100dvh] max-w-portal flex-col justify-center px-4 py-10 lg:flex-row lg:items-center lg:gap-16 lg:px-8">
            <div class="order-2 mt-10 max-w-lg lg:order-1 lg:mt-0">
                <div class="orange-rule"></div>
                <div class="heading-stack mb-6">
                    <h1 class="section-heading-white">On IT</h1>
                    <h1 class="section-heading-orange">Portal</h1>
                </div>
                <p class="portal-body max-w-md">
                    Sign in with your organisation Microsoft account to access your portals.
                </p>
            </div>

            <div class="order-1 w-full max-w-md mx-auto lg:order-2 lg:mx-0">
                <div class="form-card">
                    <p class="portal-label mb-2">Sign in</p>
                    <p class="portal-body-muted mb-6">Use your work Microsoft account.</p>

                    @if(session('error'))
                        <x-alert type="danger" class="mb-6">{{ session('error') }}</x-alert>
                    @endif

                    @if(!config('services.azure.client_id'))
                        <x-alert type="warning" class="mb-6">
                            Microsoft Entra ID is not configured yet. Add your app registration credentials to <code class="text-xs">.env</code> before signing in.
                        </x-alert>
                    @endif

                    <a href="{{ route('auth.microsoft') }}" class="cta-btn w-full justify-center">
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
        </div>
    </div>
</body>
</html>
