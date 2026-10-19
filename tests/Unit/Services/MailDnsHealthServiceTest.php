<?php

namespace Tests\Unit\Services;

use App\Services\MailDns\MailDnsHealthService;
use Tests\TestCase;

class MailDnsHealthServiceTest extends TestCase
{
    public function test_spf_and_dmarc_bands_match_locked_rules(): void
    {
        $service = new class extends MailDnsHealthService
        {
            /** @var array<string, array<int, list<array<string, mixed>>>> */
            public array $map = [];

            protected function dnsRecords(string $name, int $type): array
            {
                return $this->map[$name][$type] ?? [];
            }
        };

        $domain = 'mail-health.example';
        $service->map = [
            $domain => [
                DNS_MX => [['target' => 'mx1.mail-health.example.']],
                DNS_TXT => [
                    ['txt' => 'v=spf1 include:spf.protection.outlook.com -all'],
                ],
            ],
            '_dmarc.'.$domain => [
                DNS_TXT => [
                    ['txt' => 'v=DMARC1; p=reject; rua=mailto:dmarc@mail-health.example'],
                ],
            ],
            'selector1._domainkey.'.$domain => [
                DNS_TXT => [
                    ['txt' => 'v=DKIM1; k=rsa; p=MIGfMA0GCSqGSIb3DQEBAQUAA4GNADCBiQKBgQC'],
                ],
            ],
        ];

        $green = $service->checkDomain($domain);
        $this->assertSame('green', $green['checks']['mx']['band']);
        $this->assertSame('green', $green['checks']['spf']['band']);
        $this->assertSame('green', $green['checks']['dmarc']['band']);
        $this->assertSame('green', $green['checks']['dkim']['band']);
        $this->assertSame('green', $green['overall']);

        $service->map[$domain][DNS_TXT] = [
            ['txt' => 'v=spf1 include:spf.protection.outlook.com +all'],
        ];
        $service->map['_dmarc.'.$domain][DNS_TXT] = [
            ['txt' => 'v=DMARC1; p=none'],
        ];
        $yellow = $service->checkDomain($domain);
        $this->assertSame('yellow', $yellow['checks']['spf']['band']);
        $this->assertSame('yellow', $yellow['checks']['dmarc']['band']);
        $this->assertSame('yellow', $yellow['overall']);

        $service->map[$domain][DNS_TXT] = [];
        unset($service->map['_dmarc.'.$domain]);
        unset($service->map['selector1._domainkey.'.$domain]);
        $red = $service->checkDomain($domain);
        $this->assertSame('red', $red['checks']['spf']['band']);
        $this->assertSame('red', $red['checks']['dmarc']['band']);
        $this->assertSame('red', $red['checks']['dkim']['band']);
        $this->assertSame('red', $red['overall']);
    }
}
