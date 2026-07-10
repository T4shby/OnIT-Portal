<x-app-layout>
  <div class="mb-6"><a href="{{ route('support.index') }}" class="portal-body-muted text-sm hover:text-onit">&larr; Back to support</a></div>
  <x-card>
    <p class="portal-body-muted text-sm">#{{ $ticket['displayId'] ?? $ticket['ticketId'] }}</p>
    <h1 class="section-heading-white mt-1 !text-2xl">{{ $ticket['subject'] }}</h1>
    @if(! empty($ticket['description']))
      <div class="portal-body-muted mt-6 whitespace-pre-wrap text-sm leading-relaxed">{{ $ticket['description'] }}</div>
    @endif
  </x-card>
</x-app-layout>
