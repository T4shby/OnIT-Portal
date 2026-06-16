<x-app-layout>
  <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-8">
    <div>
      <h1 class="text-2xl font-bold text-slate-900">Support</h1>
      <p class="text-slate-500 mt-1">Your tickets. Powered by SuperOps.</p>
    </div>
    @if($user->client_id && $apiConfigured)
      <a href="{{ route('support.create') }}" class="px-4 py-2 bg-onit text-white rounded-lg hover:bg-onit-hover text-sm font-medium">New request</a>
    @endif
  </div>

  @if(! $apiConfigured)
    <x-card><x-empty-state title="SuperOps not configured" description="Add SUPEROPS_API_TOKEN and SUPEROPS_SUBDOMAIN to .env" /></x-card>
  @elseif($error)
    <x-alert type="danger" class="mb-6">{{ $error }}</x-alert>
  @elseif($tickets->isEmpty())
    <x-card><x-empty-state title="No tickets" description="Create a request when you need help." /></x-card>
  @else
    <x-card class="overflow-hidden p-0">
      <table class="min-w-full divide-y divide-slate-200">
        <thead class="bg-slate-50">
          <tr>
            <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase">ID</th>
            <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase">Subject</th>
            <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase">Status</th>
            <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase">Updated</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-200">
          @foreach($tickets as $ticket)
            <tr class="hover:bg-slate-50">
              <td class="px-6 py-4 text-sm font-medium text-onit">
                <a href="{{ route('support.show', $ticket['ticketId']) }}">#{{ $ticket['displayId'] ?? $ticket['ticketId'] }}</a>
              </td>
              <td class="px-6 py-4 text-sm">{{ $ticket['subject'] ?? '-' }}</td>
              <td class="px-6 py-4 text-sm"><x-badge variant="info">{{ is_array($ticket['status'] ?? null) ? ($ticket['status']['name'] ?? 'Open') : ($ticket['status'] ?? 'Open') }}</x-badge></td>
              <td class="px-6 py-4 text-sm text-slate-500">{{ isset($ticket['updatedTime']) ? \Carbon\Carbon::parse($ticket['updatedTime'])->format('d M Y') : '-' }}</td>
            </tr>
          @endforeach
        </tbody>
      </table>
    </x-card>
  @endif
</x-app-layout>
