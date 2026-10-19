<?php

namespace App\Jobs;

use App\Models\ClientDomain;
use App\Services\MailDns\MailDnsHealthService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CheckClientDomainDnsJob implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public int $timeout = 60;

    public function __construct(public int $clientDomainId)
    {
        $this->onQueue('default');
    }

    public function handle(MailDnsHealthService $dns): void
    {
        $domain = ClientDomain::query()->find($this->clientDomainId);
        if ($domain === null) {
            return;
        }

        try {
            $dns->refresh($domain);
        } catch (\Throwable $e) {
            Log::warning('Client domain DNS check failed', [
                'client_domain_id' => $this->clientDomainId,
                'domain' => $domain->domain,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
