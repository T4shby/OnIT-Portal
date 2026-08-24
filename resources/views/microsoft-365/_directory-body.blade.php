@php
    $pollSeconds = 5;
    $shouldPoll = (bool) ($error ? false : (
        ($display && ! $directory)
        || ($display?->refreshInProgress ?? false)
    ));
    $pollUrl = $adminContext
        ? route('admin.clients.microsoft-365.live', $client)
        : route('microsoft-365.directory.live');
@endphp

@if(! $adminContext)
    <section class="mb-8 sm:mb-10">
        <div class="orange-rule"></div>
        <div class="heading-stack mb-4">
            <h1 class="section-heading-white">Microsoft</h1>
            <h1 class="section-heading-orange">365 Directory</h1>
        </div>
        <p class="portal-body-muted max-w-2xl">
            Read-only view of licensed users, shared mailboxes, and groups in <strong class="text-white/80">{{ $client->name }}</strong>
            - without signing into the Microsoft 365 admin centre.
        </p>
        @if(($canExportDirectory ?? false) && (! ($m365Insights?->hasData() && $m365Insights->topSkus !== [])))
            <div class="mt-4">
                @include('microsoft-365._export-buttons', [
                    'adminContext' => $adminContext ?? false,
                    'client' => $client,
                ])
            </div>
        @endif
    </section>
    @include('microsoft-365._licences')
@else
    <p class="portal-body-muted mb-8 max-w-2xl">
        Read-only directory for <strong class="text-white/80">{{ $client->name }}</strong> - licensed users, shared mailboxes, and groups.
    </p>
    @if(($canExportDirectory ?? false) && (! ($m365Insights?->hasData() && $m365Insights->topSkus !== [])))
        <div class="mb-6">
            @include('microsoft-365._export-buttons', [
                'adminContext' => $adminContext ?? false,
                'client' => $client,
            ])
        </div>
    @endif
    @include('microsoft-365._licences')
@endif

<div
    id="m365-directory-live"
    data-should-poll="{{ $shouldPoll ? '1' : '0' }}"
>
    @include('microsoft-365._directory-live', ['pollSeconds' => $pollSeconds])
</div>

<x-live-fragment-poll
    :url="$pollUrl"
    target-id="m365-directory-live"
    :seconds="$pollSeconds"
    :active="$shouldPoll"
/>
