<?php

namespace App\Jobs;

use App\Services\Gdap\GdapReconcileService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class AuditGdapRelationshipsJob implements ShouldBeUnique, ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    public int $uniqueFor = 900;

    public function __construct(public ?int $clientId = null)
    {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return $this->clientId === null ? 'gdap-all' : 'gdap-client-'.$this->clientId;
    }

    public function handle(GdapReconcileService $gdap): void
    {
        if (! config('services.gdap.enabled')) {
            return;
        }

        $result = $gdap->auditAll($this->clientId);

        Log::info('GDAP audit finished', [
            'client_id' => $this->clientId,
            'checked' => $result['checked'],
            'failed' => $result['failed'],
        ]);
    }
}
