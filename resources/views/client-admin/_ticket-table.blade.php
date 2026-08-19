@php
    $title = $title ?? 'Tickets';
    $tickets = $tickets ?? [];
    $empty = $empty ?? 'No tickets in this snapshot.';
    $showResolved = $showResolved ?? false;
@endphp

<div class="org-card org-card-pad" style="padding-top:1rem;padding-bottom:.5rem">
    <p class="org-label" style="margin:0 0 10px">{{ $title }}</p>
    @if($tickets === [])
        <p class="org-muted" style="margin:0 0 10px;font-size:13px">{{ $empty }}</p>
    @else
        <div class="hidden sm:block" style="overflow-x:auto;-webkit-overflow-scrolling:touch">
            <table class="org-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Subject</th>
                        <th>Priority</th>
                        <th>Status</th>
                        @if($showResolved)
                            <th>Closed</th>
                        @else
                            <th>Opened</th>
                        @endif
                    </tr>
                </thead>
                <tbody>
                    @foreach($tickets as $ticket)
                        <tr>
                            <td style="color:#FF7000;font-family:ui-monospace,monospace;font-size:12px;white-space:nowrap">{{ $ticket['displayId'] }}</td>
                            <td style="color:rgba(255,255,255,.9);min-width:10rem">{{ $ticket['subject'] }}</td>
                            <td class="org-muted" style="white-space:nowrap">{{ $ticket['priority'] ?: '-' }}</td>
                            <td class="org-muted" style="white-space:nowrap">{{ $ticket['status'] }}</td>
                            <td class="org-muted" style="font-size:12px;white-space:nowrap">
                                @php
                                    $stamp = $showResolved ? ($ticket['resolutionTime'] ?? $ticket['createdTime'] ?? null) : ($ticket['createdTime'] ?? null);
                                @endphp
                                @if(filled($stamp))
                                    {{ \Carbon\Carbon::parse($stamp)->timezone('Europe/London')->format('d M Y') }}
                                @else
                                    -
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="portal-ticket-cards sm:hidden">
            @foreach($tickets as $ticket)
                <div class="portal-ticket-card">
                    <div class="portal-ticket-card__top">
                        <span class="portal-ticket-card__id">{{ $ticket['displayId'] }}</span>
                        <span class="org-chip">{{ $ticket['status'] }}</span>
                    </div>
                    <p class="portal-ticket-card__subject">{{ $ticket['subject'] }}</p>
                    <div class="portal-ticket-card__meta">
                        @if(filled($ticket['priority']))
                            <span>{{ $ticket['priority'] }}</span>
                        @endif
                        @php
                            $stamp = $showResolved ? ($ticket['resolutionTime'] ?? $ticket['createdTime'] ?? null) : ($ticket['createdTime'] ?? null);
                        @endphp
                        @if(filled($stamp))
                            <span>{{ $showResolved ? 'Closed' : 'Opened' }} {{ \Carbon\Carbon::parse($stamp)->timezone('Europe/London')->format('d M Y') }}</span>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>
