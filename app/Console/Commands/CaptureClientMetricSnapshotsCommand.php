<?php

namespace App\Console\Commands;

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
                    $this->warn("Client #{$client->id}: no suitable user for org snapshot - skipped");

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

    private function pickSnapshotUser(Client $client): ?User
    {
        return User::query()
            ->where('client_id', $client->id)
            ->where('is_active', true)
            ->orderByRaw("CASE role WHEN 'client_admin' THEN 0 WHEN 'client_billing_admin' THEN 1 ELSE 2 END")
            ->orderBy('id')
            ->first();
    }
}
