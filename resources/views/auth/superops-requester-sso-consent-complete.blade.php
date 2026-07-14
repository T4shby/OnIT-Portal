<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#011926">
    <title>SuperOps SSO Accept — {{ config('app.name') }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@600;700;800;900&family=Barlow:wght@300;400;500;600&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    <div class="login-panel safe-top safe-bottom">
        <div class="grid-overlay" aria-hidden="true"></div>
        <div class="relative z-[1] mx-auto flex min-h-[100dvh] max-w-portal flex-col justify-center px-4 py-10">
            <div class="mx-auto w-full max-w-lg">
                <div class="form-card">
                    <p class="portal-label mb-2">Customer tenant</p>
                    <h1 class="font-condensed text-2xl font-bold uppercase text-white mb-4">
                        {{ $success ? 'SuperOps SSO Accept complete' : 'SuperOps SSO Accept failed' }}
                    </h1>

                    @if($success)
                        <x-alert type="success" class="mb-6">
                            SuperOps Requester SSO (On IT) was accepted in the customer Microsoft tenant.
                            @if($tenant)
                                <span class="block mt-2 text-sm opacity-90">Tenant ID: {{ $tenant }}</span>
                            @endif
                        </x-alert>

                        <p class="portal-body-muted text-sm mb-6 leading-relaxed">
                            This portal success page is expected. Do
                            <strong class="text-white/80">not</strong> treat a SuperOps
                            <code class="text-white/80">usauth.superops.ai</code> JSON page as the Accept result.
                        </p>

                        <ul class="support-list mb-6 text-sm portal-body-muted">
                            <li>In the <strong class="text-white/80">customer</strong> directory → Enterprise applications → confirm <strong class="text-white/80">SuperOps Requester SSO (On IT)</strong> exists</li>
                            <li>Return to the portal checklist → continue step 08 assignment / Sync now path</li>
                        </ul>
                    @else
                        <x-alert type="error" class="mb-6">
                            Microsoft did not return a successful admin consent.
                            @if($error)
                                <span class="block mt-2 text-sm opacity-90">{{ $error }}</span>
                            @endif
                        </x-alert>

                        <p class="portal-body-muted text-sm mb-6 leading-relaxed">
                            Retry checklist step 08 from the portal. If Microsoft says the redirect URI is invalid,
                            add this Web redirect URI once on the On IT SuperOps Requester SSO app registration.
                        </p>
                    @endif

                    @if($client)
                        <a href="{{ route('login') }}" class="cta-btn w-full justify-center mb-3">
                            Sign in to portal to continue
                        </a>
                        <p class="portal-body-muted text-xs text-center">
                            After sign-in: Admin → Clients → Edit {{ $client->name }}
                        </p>
                    @else
                        <a href="{{ route('login') }}" class="cta-btn w-full justify-center">
                            Sign in to portal
                        </a>
                    @endif
                </div>
            </div>
        </div>
    </div>
</body>
</html>
