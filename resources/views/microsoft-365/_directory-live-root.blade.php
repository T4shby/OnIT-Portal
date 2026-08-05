{{-- Fragment root for live poll (must be a single element). --}}
@php
    $shouldPoll = (bool) ($error ? false : (
        ($display && ! $directory)
        || ($display?->refreshInProgress ?? false)
    ));
@endphp
<div
    id="m365-directory-live"
    data-should-poll="{{ $shouldPoll ? '1' : '0' }}"
>
    @include('microsoft-365._directory-live', ['pollSeconds' => $pollSeconds ?? 5])
</div>
