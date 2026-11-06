<?php

namespace Tests\Unit;

use App\Models\Client;
use App\Models\ClientDomain;
use App\Services\MailDns\ClientDomainService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MailDnsEmailAuthVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_m365_provider_requires_email_auth_verification(): void
    {
        $client = Client::factory()->create([
            'primary_domain' => 'contoso.example',
            'entra_tenant_id' => null,
            'onboarding_checklist' => [],
        ]);
        ClientDomain::query()->create([
            'client_id' => $client->id,
            'domain' => 'contoso.example',
            'is_primary' => true,
            'source' => 'manual',
            'last_checked_at' => now(),
            'last_result' => [
                'overall' => 'green',
                'provider' => ['key' => 'm365', 'label' => 'Microsoft 365'],
                'checks' => [
                    'mx' => ['band' => 'green'],
                    'spf' => ['band' => 'green'],
                    'dkim' => ['band' => 'green', 'selectors_found' => ['selector1', 'selector2']],
                    'dmarc' => ['band' => 'yellow'],
                ],
            ],
        ]);

        $svc = app(ClientDomainService::class);

        $this->assertTrue($svc->dnsChecklistComplete($client));
        $this->assertTrue($svc->emailAuthVerificationRequired($client));
        $this->assertFalse($svc->emailAuthVerificationComplete($client));

        $client->update([
            'onboarding_checklist' => [
                'mail_dkim_signing_enabled' => true,
                'mail_auth_test_passed' => true,
            ],
        ]);

        $this->assertTrue($svc->emailAuthVerificationComplete($client->fresh()));
    }

    public function test_dns_green_without_m365_does_not_require_defender_ticks(): void
    {
        $client = Client::factory()->create([
            'primary_domain' => 'mimecast.example',
            'entra_tenant_id' => null,
            'onboarding_checklist' => [],
        ]);
        ClientDomain::query()->create([
            'client_id' => $client->id,
            'domain' => 'mimecast.example',
            'is_primary' => true,
            'source' => 'manual',
            'last_checked_at' => now(),
            'last_result' => [
                'overall' => 'green',
                'provider' => ['key' => 'mimecast', 'label' => 'Mimecast'],
                'checks' => [
                    'dkim' => ['band' => 'green', 'selectors_found' => ['k1']],
                ],
            ],
        ]);

        $svc = app(ClientDomainService::class);

        $this->assertTrue($svc->dnsChecklistComplete($client));
        $this->assertFalse($svc->emailAuthVerificationRequired($client));
        $this->assertTrue($svc->emailAuthVerificationComplete($client));
    }

    public function test_entra_linked_client_requires_email_auth_even_before_dns_provider(): void
    {
        $client = Client::factory()->create([
            'primary_domain' => 'entra.example',
            'entra_tenant_id' => '11111111-1111-1111-1111-111111111111',
            'onboarding_checklist' => [],
        ]);

        $this->assertTrue(app(ClientDomainService::class)->emailAuthVerificationRequired($client));
    }
}
