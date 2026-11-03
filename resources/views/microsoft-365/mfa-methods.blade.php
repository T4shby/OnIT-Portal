@if($adminContext ?? false)
<x-admin-layout>
    @include('admin.partials.header', ['title' => 'MFA methods - '.$client->name])
    @include('microsoft-365._mfa-methods-body')
</x-admin-layout>
@else
<x-app-layout title="MFA methods" content-class="max-w-[96rem]">
    <div class="mb-6">
        <h1 class="text-2xl font-semibold text-white">MFA methods</h1>
        <p class="mt-1 text-sm text-white/55">Passkey and SMS / voice readiness for {{ $client->name }}</p>
    </div>
    @include('microsoft-365._mfa-methods-body')
</x-app-layout>
@endif
