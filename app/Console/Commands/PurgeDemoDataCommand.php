<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PurgeDemoDataCommand extends Command
{
    protected $signature = 'portal:purge-demo-data {--force : Run without confirmation}';

    protected $description = 'Remove demo clients (Acme, Globex, Initech) and @*.example users';

    /** @var list<string> */
    private const DEMO_CLIENT_SLUGS = [
        'acme-corporation',
        'globex-industries',
        'initech-solutions',
    ];

    public function handle(): int
    {
        if (! $this->option('force') && ! $this->confirm('Delete demo clients and @*.example users?')) {
            return self::SUCCESS;
        }

        $demoClients = Client::query()
            ->whereIn('slug', self::DEMO_CLIENT_SLUGS)
            ->get();

        $demoUsers = User::query()
            ->where(function ($query) {
                $query->where('email', 'like', '%.example')
                    ->orWhere('email', 'sarah.manager@onit.example');
            })
            ->get();

        if ($demoClients->isEmpty() && $demoUsers->isEmpty()) {
            $this->info('No demo data found.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($demoClients, $demoUsers) {
            foreach ($demoUsers as $user) {
                DB::table('client_user')->where('user_id', $user->id)->delete();
                $user->delete();
                $this->line("Removed user: {$user->email}");
            }

            foreach ($demoClients as $client) {
                $client->delete();
                $this->line("Removed client: {$client->name}");
            }
        });

        $this->info('Demo data purged.');

        return self::SUCCESS;
    }
}
