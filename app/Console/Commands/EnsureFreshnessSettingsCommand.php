<?php

namespace App\Console\Commands;

use App\Services\Portal\PortalFreshnessService;
use Illuminate\Console\Command;

/**
 * One-shot: create missing freshness.* settings rows with safe defaults (never overwrites).
 */
class EnsureFreshnessSettingsCommand extends Command
{
    protected $signature = 'portal:ensure-freshness-settings';

    protected $description = 'Insert default Integration Health auto-refresh settings if missing (does not overwrite)';

    public function handle(PortalFreshnessService $freshness): int
    {
        $created = $freshness->ensureDefaults();

        if ($created === 0) {
            $this->info('All auto-refresh settings already present — nothing to change.');
        } else {
            $this->info("Created {$created} auto-refresh setting(s) with defaults.");
        }

        foreach ($freshness->editableValues() as $key => $value) {
            $this->line("  {$key} = {$value}");
        }

        return self::SUCCESS;
    }
}
