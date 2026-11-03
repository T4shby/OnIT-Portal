@php
    $adminContext = (bool) ($adminContext ?? false);
    $canMfa = $adminContext || auth()->user()?->can('view-mfa-methods');
    $directoryUrl = $adminContext
        ? route('admin.clients.microsoft-365', $client)
        : route('microsoft-365.directory');
    $mfaUrl = $adminContext
        ? route('admin.clients.mfa-methods', $client)
        : route('microsoft-365.mfa-methods');
    $onDirectory = $adminContext
        ? request()->routeIs('admin.clients.microsoft-365*')
        : request()->routeIs('microsoft-365.directory*');
    $onMfa = $adminContext
        ? request()->routeIs('admin.clients.mfa-methods*')
        : request()->routeIs('microsoft-365.mfa-methods*');
@endphp

@if($canMfa)
    <div class="mb-6 flex flex-wrap gap-2 text-sm">
        <a href="{{ $directoryUrl }}"
           class="px-3 py-2 border {{ $onDirectory ? 'border-onit text-onit' : 'border-white/15 text-white/60 hover:text-white' }}">
            Directory
        </a>
        <a href="{{ $mfaUrl }}"
           class="px-3 py-2 border {{ $onMfa ? 'border-onit text-onit' : 'border-white/15 text-white/60 hover:text-white' }}">
            MFA methods
        </a>
    </div>
@endif
