<?php

namespace App\Http\Controllers;

use App\Services\ActivityLogService;
use App\Services\M365\MfaAuthenticationMethodsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Client Admin MFA / passkey readiness (own tenant only).
 * Staff inventory remains Admin → Clients → MFA methods.
 */
class Microsoft365MfaMethodsController extends Controller
{
    public function __construct(
        private MfaAuthenticationMethodsService $methods,
        private ActivityLogService $activityLog,
    ) {}

    public function show(Request $request): View
    {
        $user = $request->user();
        abort_unless($user->can('view-mfa-methods'), 403);

        $client = $user->client;
        abort_unless($client !== null, 404);

        $snapshot = $this->methods->cached($client);
        if ($snapshot === null && filled($client->entra_tenant_id)) {
            $snapshot = $this->methods->refresh($client);
        }

        return view('microsoft-365.mfa-methods', [
            'client' => $client,
            'snapshot' => $snapshot,
            'adminContext' => false,
        ]);
    }

    public function refresh(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->can('view-mfa-methods'), 403);

        $client = $user->client;
        abort_unless($client !== null, 404);

        if (! filled($client->entra_tenant_id)) {
            return redirect()
                ->route('microsoft-365.mfa-methods')
                ->with('error', 'Microsoft 365 is not connected yet. Ask On IT if this stays open.');
        }

        $result = $this->methods->refresh($client);
        $this->activityLog->log('client.mfa_methods_refreshed', $client, clientId: $client->id, properties: [
            'available' => $result['available'] ?? false,
            'sms_voice_risk' => $result['totals']['sms_voice_risk'] ?? 0,
            'actor_role' => $user->role->value,
        ]);

        return redirect()
            ->route('microsoft-365.mfa-methods')
            ->with($result['available'] ?? false ? 'success' : 'error', $result['recommendation'] ?? 'Refresh finished.');
    }

    public function export(Request $request): StreamedResponse|RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->can('view-mfa-methods'), 403);

        $client = $user->client;
        abort_unless($client !== null, 404);

        $snapshot = $this->methods->cached($client);
        if ($snapshot === null || ! ($snapshot['available'] ?? false)) {
            return redirect()
                ->route('microsoft-365.mfa-methods')
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
            foreach ($snapshot['users'] ?? [] as $row) {
                fputcsv($out, [
                    $row['user_display_name'] ?? '',
                    $row['user_principal_name'] ?? '',
                    ! empty($row['is_admin']) ? 'yes' : 'no',
                    ! empty($row['is_mfa_registered']) ? 'yes' : 'no',
                    ! empty($row['is_mfa_capable']) ? 'yes' : 'no',
                    ! empty($row['is_passwordless_capable']) ? 'yes' : 'no',
                    ! empty($row['sms_voice_risk']) ? 'yes' : 'no',
                    ! empty($row['has_passkey']) ? 'yes' : 'no',
                    ! empty($row['has_authenticator']) ? 'yes' : 'no',
                    implode('; ', $row['methods_registered'] ?? []),
                    implode('; ', $row['system_preferred_methods'] ?? []),
                ]);
            }
            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
