<?php

namespace App\Console\Commands;

use App\Services\Admin\IntegrationHealthAlertService;
use Illuminate\Console\Command;

class NotifyIntegrationHealthCommand extends Command
{
    protected $signature = 'portal:notify-integration-health';

    protected $description = 'Send Teams/email when Integration Health stays warning or critical';

    public function handle(IntegrationHealthAlertService $alerts): int
    {
        $result = $alerts->evaluateAndNotify();

        $this->info(sprintf(
            'severity=%s sent=%s reason=%s channels=%s',
            $result['severity'],
            $result['sent'] ? 'yes' : 'no',
            $result['reason'],
            $result['channels'] !== [] ? implode(',', $result['channels']) : '-',
        ));

        return self::SUCCESS;
    }
}
