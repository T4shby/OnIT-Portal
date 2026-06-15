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
<body class="font-sans antialiased bg-slate-50">
    <div class="min-h-screen flex items-center justify-center px-4">
        <div class="w-full max-w-md">
            <div class="text-center mb-8">
                <div class="inline-flex items-center justify-center w-16 h-16 bg-onit rounded-xl mb-4">
                    <span class="text-white font-bold text-xl">IT</span>
                </div>
                <h1 class="text-2xl font-bold text-slate-900">On IT Portal</h1>
                <p class="mt-2 text-sm text-slate-500">Your single hub for all IT services</p>
            </div>

            <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-8">
                @if(session('error'))
                    <x-alert type="danger" class="mb-6">{{ session('error') }}</x-alert>
                @endif

                @if(!config('services.azure.client_id'))
                    <x-alert type="warning" class="mb-6">
                        Microsoft Entra ID is not configured yet. Add your app registration credentials to <code class="text-xs">.env</code> before signing in.
                    </x-alert>
                @endif

                <a href="{{ route('auth.microsoft') }}"
                   class="flex items-center justify-center gap-3 w-full px-4 py-3 bg-[#2F2F2F] text-white rounded-lg hover:bg-[#1a1a1a] transition-colors font-medium">
                    <svg class="w-5 h-5" viewBox="0 0 21 21" fill="none">
                        <rect x="1" y="1" width="9" height="9" fill="#F25022"/>
                        <rect x="11" y="1" width="9" height="9" fill="#7FBA00"/>
                        <rect x="1" y="11" width="9" height="9" fill="#00A4EF"/>
                        <rect x="11" y="11" width="9" height="9" fill="#FFB900"/>
                    </svg>
                    Sign in with Microsoft
                </a>

                <p class="mt-6 text-center text-xs text-slate-400">
                    Use your work or school Microsoft account to sign in.
                </p>
            </div>
        </div>
    </div>
</body>
</html>
