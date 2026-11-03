<x-admin-layout>
    @include('admin.partials.header', ['title' => 'MFA methods - '.$client->name])
    @include('microsoft-365._mfa-methods-body', ['adminContext' => true])
</x-admin-layout>
