<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Services\ClientOnboardingService;
use Illuminate\Console\Command;

/**
 * Print Graph admin-consent URLs for every linked customer tenant (re-consent batch).
 */
class ListGraphReconsentUrlsCommand extends Command
{
    protected $signature = 'portal:graph-reconsent-urls
                            {--markdown : Print a Markdown checklist with clickable intent}
                            {--active : Only is_active clients (default: all with a tenant ID)}';

    protected $description = 'List Microsoft admin-consent URLs for re-granting Graph Application permissions per customer';

    public function handle(ClientOnboardingService $onboarding): int
    {
        $query = Client::query()
            ->whereNotNull('entra_tenant_id')
            ->where('entra_tenant_id', '!=', '')
            ->orderBy('name');

        if ($this->option('active')) {
            $query->where('is_active', true);
        }

        $rows = [];
        $query->each(function (Client $client) use ($onboarding, &$rows): void {
            $url = $onboarding->adminConsentUrl($client);
            if ($url === null) {
                return;
            }
            $rows[] = [
                'id' => $client->id,
                'name' => $client->name,
                'tenant' => (string) $client->entra_tenant_id,
                'url' => $url,
            ];
        });

        if ($rows === []) {
            $this->warn('No clients with entra_tenant_id (and MICROSOFT_CLIENT_ID configured).');

            return self::SUCCESS;
        }

        $this->info('Re-consent each tenant in a private browser under GDAP for that customer. Do not Retry Graph unless app IDs are missing.');
        $this->newLine();

        if ($this->option('markdown')) {
            $this->line('## Graph re-consent batch');
            $this->line('');
            foreach ($rows as $row) {
                $this->line("- [ ] **{$row['name']}** (`#{$row['id']}`) - [Accept]({$row['url']})");
            }
            $this->newLine();
            $this->line('_After Accept: optionally refresh M365 insights / prewarm; no SCIM/SSO redo._');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Client', 'Tenant', 'Admin consent URL'],
            array_map(static fn (array $r): array => [
                $r['id'],
                $r['name'],
                $r['tenant'],
                $r['url'],
            ], $rows),
        );

        $this->newLine();
        $this->comment('Tip: php artisan portal:graph-reconsent-urls --markdown --active');

        return self::SUCCESS;
    }
}
