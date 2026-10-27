<x-admin-layout>
    @include('admin.partials.header', ['title' => 'MFA methods - '.$client->name])

    @php
        $snapshot = is_array($snapshot ?? null) ? $snapshot : null;
        $totals = is_array($snapshot['totals'] ?? null) ? $snapshot['totals'] : [];
        $users = is_array($snapshot['users'] ?? null) ? $snapshot['users'] : [];
        $available = (bool) ($snapshot['available'] ?? false);
    @endphp

    <div class="mb-6 flex flex-wrap gap-3">
        <a href="{{ route('admin.clients.edit', $client) }}" class="cta-btn-ghost text-sm px-5 py-2">Back to Edit Client</a>
        <form method="POST" action="{{ route('admin.clients.mfa-methods.refresh', $client) }}">
            @csrf
            <button type="submit" class="cta-btn text-sm px-5 py-2">Refresh from Microsoft</button>
        </form>
        @if($available)
            <a href="{{ route('admin.clients.mfa-methods.export', $client) }}" class="cta-btn-ghost text-sm px-5 py-2">Export CSV</a>
        @endif
    </div>

    <x-card class="mb-6">
        <h2 class="admin-section-title mb-2 text-base">Passkey / SMS-voice readiness</h2>
        <p class="text-sm text-white/60 max-w-3xl mb-4">
            Microsoft begins defaulting to passkeys from 1 Sep 2026 and retires native SMS and voice MFA on 1 Feb 2027.
            Use this inventory to plan Authenticator / passkey registration before that deadline.
        </p>

        @if($snapshot === null)
            <p class="text-sm text-amber-100">No snapshot yet. Connect Microsoft, then Refresh from Microsoft.</p>
        @elseif(! $available)
            <p class="text-sm text-amber-100">{{ $snapshot['recommendation'] ?? 'Unavailable.' }}</p>
            <p class="mt-2 text-xs text-white/40">Reason code: {{ $snapshot['reason'] ?? '-' }}</p>
        @else
            <p class="text-sm text-white/80 mb-4">{{ $snapshot['recommendation'] ?? '' }}</p>
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4 text-sm">
                <div class="border border-white/10 bg-black/20 px-3 py-3">
                    <div class="text-xs uppercase tracking-wide text-white/45">Users</div>
                    <div class="text-xl text-white mt-1">{{ (int) ($totals['users'] ?? 0) }}</div>
                </div>
                <div class="border border-white/10 bg-black/20 px-3 py-3">
                    <div class="text-xs uppercase tracking-wide text-white/45">SMS / voice risk</div>
                    <div class="text-xl text-rose-300 mt-1">{{ (int) ($totals['sms_voice_risk'] ?? 0) }}</div>
                </div>
                <div class="border border-white/10 bg-black/20 px-3 py-3">
                    <div class="text-xs uppercase tracking-wide text-white/45">Authenticator</div>
                    <div class="text-xl text-emerald-300 mt-1">{{ (int) ($totals['authenticator'] ?? 0) }}</div>
                </div>
                <div class="border border-white/10 bg-black/20 px-3 py-3">
                    <div class="text-xs uppercase tracking-wide text-white/45">Passkey / passwordless</div>
                    <div class="text-xl text-emerald-300 mt-1">{{ (int) ($totals['passkey'] ?? 0) }} / {{ (int) ($totals['passwordless_capable'] ?? 0) }}</div>
                </div>
            </div>
            @if(! empty($snapshot['last_refreshed_at']))
                <p class="mt-3 text-xs text-white/40">
                    Last refreshed {{ \Illuminate\Support\Carbon::parse($snapshot['last_refreshed_at'])->diffForHumans() }}
                </p>
            @endif
        @endif
    </x-card>

    @if($available && $users !== [])
        <x-card>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs uppercase tracking-wide text-white/45">
                            <th class="py-2 pr-3">User</th>
                            <th class="py-2 pr-3">MFA</th>
                            <th class="py-2 pr-3">SMS/voice</th>
                            <th class="py-2 pr-3">Authenticator</th>
                            <th class="py-2 pr-3">Passkey</th>
                            <th class="py-2 pr-3">Methods</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($users as $user)
                            <tr class="border-t border-white/10 align-top">
                                <td class="py-3 pr-3">
                                    <div class="font-medium text-white">{{ $user['user_display_name'] ?? '-' }}</div>
                                    <div class="text-xs text-white/45">{{ $user['user_principal_name'] ?? '' }}</div>
                                    @if(! empty($user['is_admin']))
                                        <div class="text-xs text-amber-200">Admin</div>
                                    @endif
                                </td>
                                <td class="py-3 pr-3">
                                    @if(! empty($user['is_mfa_registered']))
                                        <span class="text-emerald-300">Registered</span>
                                    @else
                                        <span class="text-rose-300">Not registered</span>
                                    @endif
                                </td>
                                <td class="py-3 pr-3 {{ ! empty($user['sms_voice_risk']) ? 'text-rose-300' : 'text-white/50' }}">
                                    {{ ! empty($user['sms_voice_risk']) ? 'Yes' : 'No' }}
                                </td>
                                <td class="py-3 pr-3 {{ ! empty($user['has_authenticator']) ? 'text-emerald-300' : 'text-white/50' }}">
                                    {{ ! empty($user['has_authenticator']) ? 'Yes' : 'No' }}
                                </td>
                                <td class="py-3 pr-3 {{ ! empty($user['has_passkey']) ? 'text-emerald-300' : 'text-white/50' }}">
                                    {{ ! empty($user['has_passkey']) ? 'Yes' : 'No' }}
                                </td>
                                <td class="py-3 pr-3 text-xs text-white/60 max-w-sm">
                                    {{ implode(', ', $user['methods_registered'] ?? []) ?: '-' }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-card>
    @endif
</x-admin-layout>
