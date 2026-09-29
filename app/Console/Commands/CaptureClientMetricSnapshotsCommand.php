<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\Client;
use App\Models\User;
use App\Services\Portal\ClientHomeOverviewService;
use App\Services\Portal\ClientMetricSnapshotService;
use Illuminate\Console\Command;

class CaptureClientMetricSnapshotsCommand extends Command
{
    protected $signature = 'portal:capture-metric-snapshots {--client= : Optional client ID}';

    protected $description = 'Store a daily dashboard metric snapshot per client (for month-over-month compare)';

    public function handle(
        ClientHomeOverviewService $overview,
        ClientMetricSnapshotService $snapshots,
    ): int {
        $query = Client::query()->where('is_active', true)->orderBy('id');
        if ($this->option('client') !== null && $this->option('client') !== '') {
            $query->whereKey((int) $this->option('client'));
        }

        $count = 0;
        $query->each(function (Client $client) use ($overview, $snapshots, &$count): void {
            try {
                $user = $this->pickSnapshotUser($client);
                if ($user === null) {
                    $this->warn("Client #{$client->id}: no active client admin for an org-wide snapshot - skipped");

                    return;
                }

                $bundle = $overview->snapshotBundleForClient($client, $user);
                $snapshots->captureBundle($client, $bundle);
                $count++;
                $this->line("Captured snapshot for client #{$client->id}");
            } catch (\Throwable $e) {
                $this->error("Client #{$client->id}: {$e->getMessage()}");
                report($e);
            }
        });

        $this->info("Done. Captured {$count} snapshot(s).");

        return self::SUCCESS;
    }

    /**
     * Only a client admin sees the organisation-wide bundle. Billing admins and
     * requesters get a *personal* bundle (their own tickets/cases), which must never
     * be stored as the org's monthly snapshot and compared with org-wide figures.
     */
    private function pickSnapshotUser(Client $client): ?User
    {
        return User::query()
            ->where('client_id', $client->id)
            ->where('is_active', true)
            ->where('role', UserRole::ClientAdmin)
            ->orderBy('id')
            ->first();
    }
}
