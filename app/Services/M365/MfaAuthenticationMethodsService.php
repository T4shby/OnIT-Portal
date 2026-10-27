<?php

namespace App\Services\M365;

use App\Models\Client;
use App\Services\EntraSync\MicrosoftGraphClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Per-user MFA / passkey readiness from Graph userRegistrationDetails (Phase 1).
 */
class MfaAuthenticationMethodsService
{
    public const CACHE_KEY_PREFIX = 'mfa_auth_methods.client.';

    public const STALE_MINUTES = 1440;

    /** @var list<string> */
    public const SMS_VOICE_METHODS = [
        'mobilePhone',
        'alternateMobilePhone',
        'officePhone',
        'sms',
        'voiceMobile',
        'voiceAlternateMobile',
        'voiceOffice',
    ];

    /** @var list<string> */
    public const PASSKEY_METHODS = [
        'passKeyDeviceBound',
        'passKeyDeviceUnbound',
        'fido2',
        'windowsHelloForBusiness',
    ];

    /** @var list<string> */
    public const AUTHENTICATOR_METHODS = [
        'microsoftAuthenticatorPush',
        'microsoftAuthenticatorPasswordless',
    ];

    public function __construct(private MicrosoftGraphClient $graph) {}

    public function cacheKey(int $clientId): string
    {
        return self::CACHE_KEY_PREFIX.$clientId;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function cached(Client $client): ?array
    {
        $payload = Cache::get($this->cacheKey($client->id));

        return is_array($payload) ? $payload : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function refresh(Client $client): array
    {
        $tenantId = trim((string) $client->entra_tenant_id);
        if ($tenantId === '' || ! $this->graph->isConfigured()) {
            $payload = $this->unavailablePayload('entra_not_connected');
            Cache::put($this->cacheKey($client->id), $payload, now()->addMinutes(self::STALE_MINUTES));

            return $payload;
        }

        try {
            $details = $this->graph->listMfaUserRegistrationDetails($tenantId);
            if (! ($details['available'] ?? false)) {
                $payload = $this->unavailablePayload((string) ($details['reason'] ?? 'mfa_unavailable'));
                Cache::put($this->cacheKey($client->id), $payload, now()->addMinutes(self::STALE_MINUTES));

                return $payload;
            }

            $users = [];
            foreach ($details['users'] as $row) {
                $users[] = $this->classifyUser($row);
            }

            usort($users, static function (array $left, array $right): int {
                $risk = ((int) ($right['sms_voice_risk'] ?? false)) <=> ((int) ($left['sms_voice_risk'] ?? false));
                if ($risk !== 0) {
                    return $risk;
                }

                return strcasecmp((string) ($left['user_display_name'] ?? ''), (string) ($right['user_display_name'] ?? ''));
            });

            $payload = [
                'available' => true,
                'reason' => null,
                'last_refreshed_at' => now()->toIso8601String(),
                'totals' => $this->totals($users),
                'users' => $users,
                'recommendation' => $this->recommendation($users),
            ];

            Cache::put($this->cacheKey($client->id), $payload, now()->addMinutes(self::STALE_MINUTES));

            return $payload;
        } catch (Throwable $e) {
            Log::warning('MFA authentication methods refresh failed', [
                'client_id' => $client->id,
                'error' => $e->getMessage(),
            ]);

            $payload = $this->unavailablePayload('mfa_request_failed');
            Cache::put($this->cacheKey($client->id), $payload, now()->addMinutes(30));

            return $payload;
        }
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public function classifyUser(array $row): array
    {
        $methods = array_values(array_filter(array_map(
            static fn ($m): string => strtolower(trim((string) $m)),
            is_array($row['methods_registered'] ?? null) ? $row['methods_registered'] : [],
        )));
        $systemPreferred = array_values(array_filter(array_map(
            static fn ($m): string => strtolower(trim((string) $m)),
            is_array($row['system_preferred_methods'] ?? null) ? $row['system_preferred_methods'] : [],
        )));

        $smsVoice = $this->intersects($methods, self::SMS_VOICE_METHODS)
            || $this->intersects($systemPreferred, self::SMS_VOICE_METHODS);
        $passkey = $this->intersects($methods, self::PASSKEY_METHODS)
            || ! empty($row['is_passwordless_capable']);
        $authenticator = $this->intersects($methods, self::AUTHENTICATOR_METHODS);

        return [
            'id' => (string) ($row['id'] ?? ''),
            'user_principal_name' => $row['user_principal_name'] ?? null,
            'user_display_name' => $row['user_display_name'] ?? null,
            'is_admin' => (bool) ($row['is_admin'] ?? false),
            'is_mfa_registered' => (bool) ($row['is_mfa_registered'] ?? false),
            'is_mfa_capable' => (bool) ($row['is_mfa_capable'] ?? false),
            'is_passwordless_capable' => (bool) ($row['is_passwordless_capable'] ?? false),
            'methods_registered' => $methods,
            'system_preferred_methods' => $systemPreferred,
            'sms_voice_risk' => $smsVoice,
            'has_passkey' => $passkey,
            'has_authenticator' => $authenticator,
            'last_updated_at' => $row['last_updated_at'] ?? null,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $users
     * @return array<string, int>
     */
    private function totals(array $users): array
    {
        $total = count($users);
        $sms = 0;
        $passkey = 0;
        $authenticator = 0;
        $mfaRegistered = 0;
        $passwordless = 0;
        $smsWithoutPasskey = 0;

        foreach ($users as $user) {
            if (! empty($user['sms_voice_risk'])) {
                $sms++;
                if (empty($user['has_passkey']) && empty($user['has_authenticator'])) {
                    $smsWithoutPasskey++;
                }
            }
            if (! empty($user['has_passkey'])) {
                $passkey++;
            }
            if (! empty($user['has_authenticator'])) {
                $authenticator++;
            }
            if (! empty($user['is_mfa_registered'])) {
                $mfaRegistered++;
            }
            if (! empty($user['is_passwordless_capable'])) {
                $passwordless++;
            }
        }

        return [
            'users' => $total,
            'mfa_registered' => $mfaRegistered,
            'sms_voice_risk' => $sms,
            'sms_voice_without_strong' => $smsWithoutPasskey,
            'passkey' => $passkey,
            'authenticator' => $authenticator,
            'passwordless_capable' => $passwordless,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $users
     */
    private function recommendation(array $users): string
    {
        $totals = $this->totals($users);
        if ($totals['users'] === 0) {
            return 'No member users in the authentication methods report.';
        }

        if ($totals['sms_voice_risk'] === 0) {
            return 'No SMS/voice methods detected in the report. Prefer keeping Microsoft Authenticator / passkeys as default before Feb 2027.';
        }

        $parts = [
            $totals['sms_voice_risk'].' user(s) still show SMS/voice methods.',
        ];
        if ($totals['sms_voice_without_strong'] > 0) {
            $parts[] = $totals['sms_voice_without_strong'].' of those lack Authenticator/passkey - prioritise a registration campaign (Microsoft Authenticator first; FIDO2 / Windows Hello where already present).';
        } else {
            $parts[] = 'Those users also have Authenticator/passkey - plan to remove SMS/voice after confirming sign-in works with the stronger method.';
        }

        return implode(' ', $parts);
    }

    /**
     * @return array<string, mixed>
     */
    private function unavailablePayload(string $reason): array
    {
        return [
            'available' => false,
            'reason' => $reason,
            'last_refreshed_at' => now()->toIso8601String(),
            'totals' => [
                'users' => 0,
                'mfa_registered' => 0,
                'sms_voice_risk' => 0,
                'sms_voice_without_strong' => 0,
                'passkey' => 0,
                'authenticator' => 0,
                'passwordless_capable' => 0,
            ],
            'users' => [],
            'recommendation' => match ($reason) {
                'entra_not_connected' => 'Connect Microsoft for this client before MFA methods can load.',
                'mfa_permission_missing' => 'Graph denied the auth methods report (AuditLog.Read.All / Reports.Read.All + Entra ID P1). Re-consent if needed.',
                'mfa_empty' => 'The authentication methods report returned no member users.',
                default => 'MFA methods are temporarily unavailable from Microsoft Graph.',
            },
        ];
    }

    /**
     * @param  list<string>  $haystack
     * @param  list<string>  $needles
     */
    private function intersects(array $haystack, array $needles): bool
    {
        $needleSet = [];
        foreach ($needles as $needle) {
            $needleSet[strtolower($needle)] = true;
        }
        foreach ($haystack as $item) {
            if (isset($needleSet[strtolower($item)])) {
                return true;
            }
        }

        return false;
    }
}
