<x-app-layout>
  <div class="mb-6"><a href="{{ route('support.index') }}" class="text-sm text-onit font-medium">← Back</a></div>
  <x-card>
    <p class="text-sm text-slate-500">#{{ $ticket['displayId'] ?? $ticket['ticketId'] }}</p>
    <h1 class="text-2xl font-bold text-slate-900 mt-1">{{ $ticket['subject'] }}</h1>
    @if(! empty($ticket['description']))
      <div class="mt-6 text-sm text-slate-700 whitespace-pre-wrap">{{ $ticket['description'] }}</div>
    @endif
  </x-card>
</x-app-layout>
