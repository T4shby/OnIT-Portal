<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Services\EntraSync\EntraGroupSyncService;
use Illuminate\Console\Command;

class RevertEntraDisplayNamesCommand extends Command
{
    protected $signature = 'portal:revert-entra-display-names
                            {--client= : Revert display names for a single client by ID}
                            {--dry-run : Show how many names would be restored without writing to Entra}';

    protected $description = 'Strip (User) / (Shared Mailbox) from M365 display names only — does not delete or disable any users';

    public function handle(EntraGroupSyncService $sync): int
    {
        $clientId = $this->option('client');

        if (! $clientId) {
            $this->error('Pass --client={id} for the customer to fix.');

            return self::FAILURE;
        }

        $client = Client::query()->find($clientId);

        if (! $client) {
            $this->error("Client #{$clientId} not found.");

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->warn('Dry run: Entra display names will not be changed.');
        }

        $result = $sync->revertEntraDisplayNames($client, $dryRun);

        $this->info("Client: {$client->name} (#{$client->id})");
        $this->line('  Display names restored: '.$result['reverted']);

        foreach ($result['errors'] as $error) {
            $this->warn('  '.$error);
        }

        if ($result['reverted'] > 0 && ! $dryRun) {
            $this->newLine();
            $this->comment('M365 display names restored — no users were deleted or disabled. Run sync + SCIM mapping so SuperOps shows (User) / (Shared Mailbox) only there.');
        }

        return $result['errors'] !== [] && $result['reverted'] === 0 ? self::FAILURE : self::SUCCESS;
    }
}
