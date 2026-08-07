@php
    $d = $summary;
@endphp
<x-app-layout title="Online backups" content-class="max-w-[96rem]">
    <section class="mb-6">
        <div class="orange-rule"></div>
        <div class="heading-stack mb-4">
            <h1 class="section-heading-white">Online</h1>
            <h1 class="section-heading-orange">Backups</h1>
        </div>
        <p class="portal-body-muted max-w-3xl">
            Protected mailboxes, OneDrive, and SharePoint for
            <strong class="text-white/80">{{ $client->name }}</strong>
            via Dropsuite.
            @if($d->lastRefreshedAt)
                Snapshot as of {{ $d->lastRefreshedAt->timezone('Europe/London')->format('d M Y H:i') }} UK.
            @endif
        </p>
        <div class="mt-4">
            <a href="{{ route('client-admin.dashboard') }}" class="text-xs text-onit hover:text-white font-condensed uppercase tracking-wide">
                &larr; Organisation overview
            </a>
        </div>
    </section>

    @include('client-admin._backups-detail', ['summary' => $summary])
</x-app-layout>
