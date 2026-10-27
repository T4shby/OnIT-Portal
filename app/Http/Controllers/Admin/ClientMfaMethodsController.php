<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Services\ActivityLogService;
use App\Services\M365\MfaAuthenticationMethodsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ClientMfaMethodsController extends Controller
{
    public function __construct(
        private MfaAuthenticationMethodsService $methods,
        private ActivityLogService $activityLog,
    ) {}

    public function show(Client $client): View
    {
        $this->authorize('update', $client);

        $snapshot = $this->methods->cached($client);
        if ($snapshot === null && filled($client->entra_tenant_id)) {
            $snapshot = $this->methods->refresh($client);
        }

        return view('admin.clients.mfa-methods', [
            'client' => $client,
            'snapshot' => $snapshot,
        ]);
    }

    public function refresh(Client $client): RedirectResponse
    {
        $this->authorize('update', $client);

        if (! filled($client->entra_tenant_id)) {
            return redirect()
                ->route('admin.clients.mfa-methods', $client)
                ->with('error', 'Connect Microsoft first so the tenant ID is saved.');
        }

        // Sync refresh so the page is useful immediately when testing.
        $result = $this->methods->refresh($client);
        $this->activityLog->log('client.mfa_methods_refreshed', $client, clientId: $client->id, properties: [
            'available' => $result['available'] ?? false,
            'sms_voice_risk' => $result['totals']['sms_voice_risk'] ?? 0,
        ]);

        return redirect()
            ->route('admin.clients.mfa-methods', $client)
            ->with($result['available'] ?? false ? 'success' : 'error', $result['recommendation'] ?? 'Refresh finished.');
    }

    public function export(Client $client): StreamedResponse|RedirectResponse
    {
        $this->authorize('update', $client);

        $snapshot = $this->methods->cached($client);
        if ($snapshot === null || ! ($snapshot['available'] ?? false)) {
            return redirect()
                ->route('admin.clients.mfa-methods', $client)
                ->with('error', 'Refresh MFA methods first, then export.');
        }

        $filename = 'mfa-methods-'.$client->slug.'-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($snapshot): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, [
                'display_name',
                'user_principal_name',
                'is_admin',
                'mfa_registered',
                'mfa_capable',
                'passwordless_capable',
                'sms_voice_risk',
                'has_passkey',
                'has_authenticator',
                'methods_registered',
                'system_preferred_methods',
            ]);
            foreach ($snapshot['users'] ?? [] as $user) {
                fputcsv($out, [
                    $user['user_display_name'] ?? '',
                    $user['user_principal_name'] ?? '',
                    ! empty($user['is_admin']) ? 'yes' : 'no',
                    ! empty($user['is_mfa_registered']) ? 'yes' : 'no',
                    ! empty($user['is_mfa_capable']) ? 'yes' : 'no',
                    ! empty($user['is_passwordless_capable']) ? 'yes' : 'no',
                    ! empty($user['sms_voice_risk']) ? 'yes' : 'no',
                    ! empty($user['has_passkey']) ? 'yes' : 'no',
                    ! empty($user['has_authenticator']) ? 'yes' : 'no',
                    implode('; ', $user['methods_registered'] ?? []),
                    implode('; ', $user['system_preferred_methods'] ?? []),
                ]);
            }
            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
