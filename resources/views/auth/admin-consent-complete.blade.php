<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#011926">
    <title>Admin consent granted — {{ config('app.name') }}</title>
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
                    <h1 class="font-condensed text-2xl font-bold uppercase text-white mb-4">Admin consent granted</h1>

                    <x-alert type="success" class="mb-6">
                        OnIT Portal for Portals is now consented in the customer Microsoft tenant.
                        @if($tenant)
                            <span class="block mt-2 text-sm opacity-90">Tenant ID: {{ $tenant }}</span>
                        @endif
                    </x-alert>

                    <p class="portal-body-muted text-sm mb-6 leading-relaxed">
                        This page is expected — Microsoft redirects here after you click Accept. It is
                        <strong class="text-white/80">not</strong> a failed login.
                    </p>

                    <ul class="support-list mb-6 text-sm portal-body-muted">
                        <li>In <strong class="text-white/80">customer</strong> Entra → Enterprise applications → OnIT Portal for Portals → Permissions — confirm all show <strong class="text-white/80">Granted</strong></li>
                        <li>Return to the portal checklist and continue with SuperOps SCIM, then Dry run sync</li>
                    </ul>

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
