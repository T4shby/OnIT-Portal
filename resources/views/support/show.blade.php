<x-app-layout title="Ticket" content-class="max-w-[96rem]">
  <div class="mb-6"><a href="{{ route('support.index') }}" class="portal-body-muted text-sm hover:text-onit">&larr; Back to support</a></div>
  <x-card>
    <p class="portal-body-muted text-sm">#{{ $ticket['displayId'] ?? $ticket['ticketId'] }}</p>
    <h1 class="section-heading-white mt-1 !text-2xl">{{ $ticket['subject'] }}</h1>
    @if(! empty($ticket['description']))
      <div class="portal-ticket-body portal-body-muted mt-6 text-sm leading-relaxed [&_p]:mb-3 [&_ul]:my-3 [&_li]:mb-1.5 [&_strong]:text-white/90">
        {!! \App\Support\SuperOpsHtml::sanitize((string) $ticket['description']) !!}
      </div>
    @endif
    <div class="mt-8 pt-6 border-t border-white/10">
      <p class="text-sm portal-body-muted leading-relaxed mb-4">
        Comments and attachments are not shown here. Open the full service desk for the conversation and updates.
      </p>
      <a href="{{ route('integrations.superops.launch') }}" class="cta-btn text-sm">Open full service desk →</a>
    </div>
  </x-card>
</x-app-layout>
