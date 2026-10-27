<?php

namespace Tests\Unit\Services;

use App\Services\M365\MfaAuthenticationMethodsService;
use Tests\TestCase;

class MfaAuthenticationMethodsServiceTest extends TestCase
{
    public function test_classifies_sms_voice_and_passkey_signals(): void
    {
        $service = app(MfaAuthenticationMethodsService::class);

        $smsOnly = $service->classifyUser([
            'id' => '1',
            'user_display_name' => 'Sms User',
            'user_principal_name' => 'sms@example.com',
            'is_admin' => false,
            'is_mfa_registered' => true,
            'is_mfa_capable' => true,
            'is_passwordless_capable' => false,
            'methods_registered' => ['mobilePhone', 'password'],
            'system_preferred_methods' => ['sms'],
            'last_updated_at' => null,
        ]);

        $this->assertTrue($smsOnly['sms_voice_risk']);
        $this->assertFalse($smsOnly['has_passkey']);
        $this->assertFalse($smsOnly['has_authenticator']);

        $passkey = $service->classifyUser([
            'id' => '2',
            'user_display_name' => 'Passkey User',
            'user_principal_name' => 'pk@example.com',
            'is_admin' => true,
            'is_mfa_registered' => true,
            'is_mfa_capable' => true,
            'is_passwordless_capable' => true,
            'methods_registered' => ['passKeyDeviceBound', 'microsoftAuthenticatorPush'],
            'system_preferred_methods' => ['push'],
            'last_updated_at' => null,
        ]);

        $this->assertFalse($passkey['sms_voice_risk']);
        $this->assertTrue($passkey['has_passkey']);
        $this->assertTrue($passkey['has_authenticator']);
    }
}
