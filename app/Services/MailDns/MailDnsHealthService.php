<?php

namespace App\Services\MailDns;

use App\Models\ClientDomain;
use App\Support\MailDomain;
use Carbon\Carbon;

class MailDnsHealthService
{
    /**
     * Known DKIM selectors across common mail/security platforms.
     *
     * @var list<string>
     */
    public const DEFAULT_DKIM_SELECTORS = [
        'selector1', 'selector2', // Microsoft 365
        's1', 's2', 's3',
        'google',
        'default', 'dkim',
        'k1', 'k2', 'k3',
        'cm', 'sm',
        'mimecast',
        's1024', 's2048',
        'mailgun', 'mg',
        'sendgrid', 'smtpapi',
        'everlytick',
        'zendesk1', 'zendesk2',
        'amazonses',
        'mandrill',
        'protonmail',
        'fm1', 'fm2', 'fm3', // Fastmail-style
        'mxvault',
    ];

    /**
     * @return array{
     *   overall: string,
     *   checked_at: string,
     *   domain: string,
     *   checks: array<string, array{band: string, summary: string, detail: ?string, records?: list<string>}>
     * }
     */
    public function checkDomain(string $domain, array $extraSelectors = []): array
    {
        $domain = MailDomain::normalize($domain) ?? '';
        $mx = $this->checkMx($domain);
        $spf = $this->checkSpf($domain);
        $dmarc = $this->checkDmarc($domain);
        $dkim = $this->checkDkim($domain, $extraSelectors);

        $checks = [
            'mx' => $mx,
            'spf' => $spf,
            'dkim' => $dkim,
            'dmarc' => $dmarc,
        ];

        return [
            'overall' => $this->worstBand(array_column($checks, 'band')),
            'checked_at' => now()->toIso8601String(),
            'domain' => $domain,
            'checks' => $checks,
        ];
    }

    public function refresh(ClientDomain $clientDomain): ClientDomain
    {
        $extra = is_array($clientDomain->dkim_selectors) ? $clientDomain->dkim_selectors : [];
        $result = $this->checkDomain($clientDomain->domain, $extra);

        $clientDomain->forceFill([
            'last_checked_at' => Carbon::parse($result['checked_at']),
            'last_result' => $result,
        ])->save();

        return $clientDomain->refresh();
    }

    /**
     * @return array{band: string, summary: string, detail: ?string, records: list<string>}
     */
    private function checkMx(string $domain): array
    {
        $records = $this->dnsRecords($domain, DNS_MX);
        $hosts = [];
        foreach ($records as $row) {
            $host = rtrim((string) ($row['target'] ?? ''), '.');
            if ($host !== '') {
                $hosts[] = $host;
            }
        }
        $hosts = array_values(array_unique($hosts));

        if ($hosts === []) {
            return [
                'band' => 'red',
                'summary' => 'No MX record',
                'detail' => 'Mail cannot be delivered until MX is published.',
                'records' => [],
            ];
        }

        return [
            'band' => 'green',
            'summary' => count($hosts) === 1 ? 'MX present' : count($hosts).' MX hosts',
            'detail' => implode(', ', $hosts),
            'records' => $hosts,
        ];
    }

    /**
     * @return array{band: string, summary: string, detail: ?string, records: list<string>}
     */
    private function checkSpf(string $domain): array
    {
        $txt = $this->txtStrings($domain);
        $spf = array_values(array_filter($txt, fn (string $v) => str_starts_with(strtolower($v), 'v=spf1')));

        if ($spf === []) {
            return [
                'band' => 'red',
                'summary' => 'No SPF record',
                'detail' => 'Publish a single TXT v=spf1 policy on the apex.',
                'records' => [],
            ];
        }

        if (count($spf) > 1) {
            return [
                'band' => 'yellow',
                'summary' => 'Multiple SPF records',
                'detail' => 'Only one SPF TXT is valid. Merge into a single policy.',
                'records' => $spf,
            ];
        }

        $policy = $spf[0];
        $lower = strtolower($policy);
        if (str_contains($lower, '+all')) {
            return [
                'band' => 'yellow',
                'summary' => 'SPF allows anyone (+all)',
                'detail' => $policy,
                'records' => $spf,
            ];
        }

        if (! str_contains($lower, '-all') && ! str_contains($lower, '~all')) {
            return [
                'band' => 'yellow',
                'summary' => 'SPF present but no ~all/-all',
                'detail' => $policy,
                'records' => $spf,
            ];
        }

        return [
            'band' => 'green',
            'summary' => 'SPF looks correct',
            'detail' => $policy,
            'records' => $spf,
        ];
    }

    /**
     * @return array{band: string, summary: string, detail: ?string, records: list<string>}
     */
    private function checkDmarc(string $domain): array
    {
        $txt = $this->txtStrings('_dmarc.'.$domain);
        $dmarc = array_values(array_filter($txt, fn (string $v) => str_starts_with(strtolower($v), 'v=dmarc1')));

        if ($dmarc === []) {
            return [
                'band' => 'red',
                'summary' => 'No DMARC record',
                'detail' => 'Publish TXT on _dmarc.'.$domain,
                'records' => [],
            ];
        }

        $policy = $dmarc[0];
        if (preg_match('/\bp=none\b/i', $policy)) {
            return [
                'band' => 'yellow',
                'summary' => 'DMARC set to p=none',
                'detail' => 'Present but not enforcing. Move to quarantine or reject when ready.',
                'records' => $dmarc,
            ];
        }

        if (preg_match('/\bp=(quarantine|reject)\b/i', $policy)) {
            return [
                'band' => 'green',
                'summary' => 'DMARC enforcing',
                'detail' => $policy,
                'records' => $dmarc,
            ];
        }

        return [
            'band' => 'yellow',
            'summary' => 'DMARC present but policy unclear',
            'detail' => $policy,
            'records' => $dmarc,
        ];
    }

    /**
     * @param  list<string|mixed>  $extraSelectors
     * @return array{band: string, summary: string, detail: ?string, records: list<string>, selectors_found: list<string>}
     */
    private function checkDkim(string $domain, array $extraSelectors): array
    {
        $selectors = array_values(array_unique(array_filter(array_map(
            function ($s): string {
                $s = strtolower(trim((string) $s));
                $s = preg_replace('/[^a-z0-9._-]/', '', $s) ?? $s;

                return $s;
            },
            array_merge(self::DEFAULT_DKIM_SELECTORS, $extraSelectors),
        ))));

        $found = [];
        $weak = [];
        foreach ($selectors as $selector) {
            $name = $selector.'._domainkey.'.$domain;
            $txt = $this->txtStrings($name);
            if ($txt === []) {
                // Follow a single CNAME hop (common for M365 / gateways).
                $cname = $this->dnsRecords($name, DNS_CNAME);
                foreach ($cname as $row) {
                    $target = rtrim((string) ($row['target'] ?? ''), '.');
                    if ($target !== '') {
                        $txt = array_merge($txt, $this->txtStrings($target));
                    }
                }
            }

            foreach ($txt as $value) {
                $lower = strtolower($value);
                if (! str_contains($lower, 'v=dkim1') && ! str_contains($lower, 'p=')) {
                    continue;
                }
                if (preg_match('/\bp=\s*;/i', $value) || preg_match('/\bp=$/i', trim($value))) {
                    $weak[] = $selector;

                    continue;
                }
                if (str_contains($lower, 'p=')) {
                    $found[] = $selector;
                }
            }
        }

        $found = array_values(array_unique($found));
        $weak = array_values(array_unique(array_diff($weak, $found)));

        if ($found !== []) {
            return [
                'band' => 'green',
                'summary' => count($found) === 1
                    ? 'DKIM key on '.$found[0]
                    : 'DKIM keys on '.count($found).' selectors',
                'detail' => 'Found: '.implode(', ', $found),
                'records' => $found,
                'selectors_found' => $found,
            ];
        }

        if ($weak !== []) {
            return [
                'band' => 'yellow',
                'summary' => 'DKIM selector present but empty key',
                'detail' => 'Selectors: '.implode(', ', $weak),
                'records' => $weak,
                'selectors_found' => $weak,
            ];
        }

        return [
            'band' => 'red',
            'summary' => 'No DKIM key found',
            'detail' => 'Tried common selectors (including M365 selector1/selector2). Add a custom selector if you use another gateway.',
            'records' => [],
            'selectors_found' => [],
        ];
    }

    /**
     * @return list<string>
     */
    private function txtStrings(string $name): array
    {
        $out = [];
        foreach ($this->dnsRecords($name, DNS_TXT) as $row) {
            $chunks = $row['entries'] ?? $row['txt'] ?? null;
            if (is_array($chunks)) {
                $out[] = implode('', $chunks);
            } elseif (is_string($chunks) && $chunks !== '') {
                $out[] = $chunks;
            }
        }

        return array_values(array_filter(array_map('trim', $out)));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function dnsRecords(string $name, int $type): array
    {
        if ($name === '') {
            return [];
        }

        try {
            $records = @dns_get_record($name, $type);
        } catch (\Throwable) {
            return [];
        }

        return is_array($records) ? $records : [];
    }

    /**
     * @param  list<string>  $bands
     */
    private function worstBand(array $bands): string
    {
        if (in_array('red', $bands, true)) {
            return 'red';
        }
        if (in_array('yellow', $bands, true)) {
            return 'yellow';
        }

        return 'green';
    }
}
