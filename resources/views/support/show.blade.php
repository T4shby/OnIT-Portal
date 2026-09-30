<x-app-layout title="Ticket" content-class="max-w-[96rem]">
  <div class="mb-6"><a href="{{ route('support.index') }}" class="portal-body-muted text-sm hover:text-onit">&larr; Back to support</a></div>
  <x-card>
    <p class="portal-body-muted text-sm">#{{ $ticket['displayId'] ?? $ticket['ticketId'] }}</p>
    <h1 class="section-heading-white mt-1 !text-2xl">{{ $ticket['subject'] }}</h1>
    @php
      $statusLabel = is_array($ticket['status'] ?? null)
        ? (string) ($ticket['status']['name'] ?? '')
        : (string) ($ticket['status'] ?? '');
    @endphp
    @if($statusLabel !== '')
      <p class="mt-3 text-sm text-white/80">{{ $statusLabel }}</p>
    @endif

    @if(! empty($ticket['conversations']))
      <div class="mt-8">
        <p class="portal-label mb-4">What we have done</p>
        <ol class="space-y-4">
          @foreach($ticket['conversations'] as $entry)
            @php
              try {
                $when = filled($entry['at'] ?? null)
                  ? \Illuminate\Support\Carbon::parse($entry['at'])->timezone('Europe/London')->format('d M Y · H:i')
                  : null;
              } catch (\Throwable) {
                $when = null;
              }
            @endphp
            <li class="border border-white/10 px-4 py-3">
              <div class="flex flex-wrap items-baseline justify-between gap-2">
                <p class="text-sm font-semibold text-white">{{ $entry['title'] ?? 'Update' }}</p>
                @if($when)
                  <p class="text-xs portal-body-muted">{{ $when }} UK</p>
                @endif
              </div>
              <div class="portal-ticket-body portal-body-muted mt-2 text-sm leading-relaxed [&_p]:mb-3 [&_ul]:my-3 [&_li]:mb-1.5 [&_strong]:text-white/90">
                {!! \App\Support\SuperOpsHtml::sanitize((string) ($entry['content'] ?? '')) !!}
              </div>
            </li>
          @endforeach
        </ol>
      </div>
    @elseif(! empty($ticket['description']))
      <div class="portal-ticket-body portal-body-muted mt-6 text-sm leading-relaxed [&_p]:mb-3 [&_ul]:my-3 [&_li]:mb-1.5 [&_strong]:text-white/90">
        {!! \App\Support\SuperOpsHtml::sanitize((string) $ticket['description']) !!}
      </div>
    @endif

    <div class="mt-8 pt-6 border-t border-white/10">
      <p class="text-sm portal-body-muted leading-relaxed mb-4">
        These are the replies on the ticket. Internal notes stay with the technician. Files stay in the full service desk.
      </p>
      <a href="{{ route('integrations.superops.launch') }}" class="cta-btn text-sm">Open full service desk →</a>
    </div>
  </x-card>
</x-app-layout>
