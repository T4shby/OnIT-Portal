<x-app-layout title="Support" content-class="max-w-[96rem]">
  <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between mb-8">
    <div>
      <h1 class="section-heading-white !text-2xl">Support</h1>
      <p class="portal-body-muted mt-1">Create and track tickets here. Conversation threads and attachments open in SuperOps.</p>
    </div>
    <div class="flex flex-col sm:flex-row gap-3 w-full sm:w-auto">
      @if($user->client_id && $apiConfigured)
        <a href="{{ route('support.create') }}" class="cta-btn w-full justify-center text-sm sm:w-auto">New request</a>
      @endif
      <a href="{{ route('integrations.superops.launch') }}" class="cta-btn-ghost w-full justify-center text-sm sm:w-auto">Open SuperOps →</a>
    </div>
  </div>

  <x-card class="mb-6">
    <p class="text-sm portal-body-muted leading-relaxed">
      The portal shows your ticket list and opening description only.
      Replies, comments, and files stay in SuperOps - use <strong class="text-white/80">Open SuperOps</strong> for the full conversation.
    </p>
  </x-card>

  @if(! $apiConfigured)
    <x-card><x-empty-state title="SuperOps not configured" description="Add SUPEROPS_API_TOKEN and SUPEROPS_SUBDOMAIN to .env" /></x-card>
  @elseif($error)
    <x-alert type="danger" class="mb-6">{{ $error }}</x-alert>
  @elseif($tickets->isEmpty())
    <x-card><x-empty-state title="No tickets" description="Create a request when you need help." /></x-card>
  @else
    <div class="hidden sm:block">
      <x-card class="p-0">
        <div class="portal-table-wrap">
          <table class="min-w-full">
            <thead>
              <tr class="border-b border-white/10">
                <th class="whitespace-nowrap px-3 py-3 text-left font-condensed text-xs font-bold uppercase tracking-wider text-onit sm:px-6">ID</th>
                <th class="whitespace-nowrap px-3 py-3 text-left font-condensed text-xs font-bold uppercase tracking-wider text-onit sm:px-6">Subject</th>
                <th class="whitespace-nowrap px-3 py-3 text-left font-condensed text-xs font-bold uppercase tracking-wider text-onit sm:px-6">Status</th>
                <th class="whitespace-nowrap px-3 py-3 text-left font-condensed text-xs font-bold uppercase tracking-wider text-onit sm:px-6">Updated</th>
              </tr>
            </thead>
            <tbody>
              @foreach($tickets as $ticket)
                <tr class="border-t border-white/5 transition-colors hover:bg-white/[0.02]">
                  <td class="whitespace-nowrap px-3 py-4 text-sm font-medium text-onit sm:px-6">
                    <a href="{{ route('support.show', $ticket['ticketId']) }}" class="hover:underline">#{{ $ticket['displayId'] ?? $ticket['ticketId'] }}</a>
                  </td>
                  <td class="px-3 py-4 text-sm text-white/80 sm:px-6">{{ $ticket['subject'] ?? '-' }}</td>
                  <td class="whitespace-nowrap px-3 py-4 text-sm sm:px-6"><x-badge variant="info">{{ is_array($ticket['status'] ?? null) ? ($ticket['status']['name'] ?? 'Open') : ($ticket['status'] ?? 'Open') }}</x-badge></td>
                  <td class="whitespace-nowrap px-3 py-4 text-sm text-white/50 sm:px-6">{{ isset($ticket['updatedTime']) ? \Carbon\Carbon::parse($ticket['updatedTime'])->format('d M Y') : '-' }}</td>
                </tr>
              @endforeach
            </tbody>
          </table>
        </div>
      </x-card>
    </div>

    <div class="portal-ticket-cards sm:hidden">
      @foreach($tickets as $ticket)
        @php
          $status = is_array($ticket['status'] ?? null) ? ($ticket['status']['name'] ?? 'Open') : ($ticket['status'] ?? 'Open');
          $updated = isset($ticket['updatedTime']) ? \Carbon\Carbon::parse($ticket['updatedTime'])->format('d M Y') : null;
        @endphp
        <a href="{{ route('support.show', $ticket['ticketId']) }}" class="portal-ticket-card">
          <div class="portal-ticket-card__top">
            <span class="portal-ticket-card__id">#{{ $ticket['displayId'] ?? $ticket['ticketId'] }}</span>
            <x-badge variant="info">{{ $status }}</x-badge>
          </div>
          <p class="portal-ticket-card__subject">{{ $ticket['subject'] ?? 'No subject' }}</p>
          <div class="portal-ticket-card__meta">
            @if($updated)
              <span>Updated {{ $updated }}</span>
            @endif
          </div>
        </a>
      @endforeach
    </div>
  @endif
</x-app-layout>
