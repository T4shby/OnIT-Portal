<?php

namespace App\Services\EntraSync;

use App\Enums\UserProvisionSource;
use App\Enums\UserRole;
use App\Models\Client;
use App\Models\User;
use App\Services\ExternalServicesService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class EntraGroupSyncService
{
    public function __construct(
        private MicrosoftGraphClient $graph,
        private ExternalServicesService $portalLinks,
    ) {}

    public function syncClient(Client $client, bool $dryRun = false): EntraSyncResult
    {
        if (! $client->hasEntraSyncConfigured()) {
            return new EntraSyncResult(errors: ['Client does not have Entra sync configured.']);
        }

        if (! config('services.entra_sync.enabled')) {
            return new EntraSyncResult(errors: ['Entra sync is disabled (ENTRA_SYNC_ENABLED=false).']);
        }

        if (! $this->graph->isConfigured()) {
            return new EntraSyncResult(errors: ['Microsoft Graph credentials are not configured.']);
        }

        try {
            $graphUsers = $this->graph->listGroupUsers(
                $client->entra_tenant_id,
                $client->entra_group_id,
            );
        } catch (Throwable $e) {
            Log::error('Entra group sync failed to read Graph', [
                'client_id' => $client->id,
                'error' => $e->getMessage(),
            ]);

            return new EntraSyncResult(errors: [$e->getMessage()]);
        }

        $created = 0;
        $updated = 0;
        $deactivated = 0;
        $skipped = 0;
        $errors = [];
        $activeObjectIds = [];
        $activeEmails = [];

        foreach ($graphUsers as $graphUser) {
            $email = $this->resolveEmail($graphUser);

            if ($email === null) {
                $skipped++;
                $errors[] = 'Skipped Graph user '.$graphUser['id'].' (no mail or userPrincipalName).';

                continue;
            }

            $activeObjectIds[] = $graphUser['id'];
            $activeEmails[] = $email;

            $shouldBeActive = $graphUser['accountEnabled'];
            $name = $graphUser['displayName'] ?: Str::before($email, '@');

            $existing = User::query()
                ->whereRaw('LOWER(email) = ?', [$email])
                ->first();

            if ($existing && $existing->client_id !== $client->id) {
                $skipped++;
                $errors[] = "Email {$email} already belongs to another client (user #{$existing->id}).";

                continue;
            }

            if ($existing && in_array($existing->role, [UserRole::SuperAdmin, UserRole::AccountManager], true)) {
                $skipped++;
                $errors[] = "Skipped admin account {$email}.";

                continue;
            }

            if ($existing && $existing->provisioned_by === UserProvisionSource::Manual) {
                $skipped++;
                $errors[] = "Skipped manual user {$email}.";

                continue;
            }

            if ($dryRun) {
                if ($existing) {
                    $updated++;
                } else {
                    $created++;
                }

                continue;
            }

            if ($existing) {
                $existing->update([
                    'name' => $name,
                    'entra_object_id' => $graphUser['id'],
                    'is_active' => $shouldBeActive,
                    'provisioned_by' => UserProvisionSource::EntraSync,
                    'entra_synced_at' => now(),
                ]);
                $updated++;
            } else {
                User::create([
                    'client_id' => $client->id,
                    'email' => $email,
                    'name' => $name,
                    'role' => UserRole::ClientUser,
                    'is_active' => $shouldBeActive,
                    'entra_object_id' => $graphUser['id'],
                    'provisioned_by' => UserProvisionSource::EntraSync,
                    'entra_synced_at' => now(),
                ]);
                $created++;
            }
        }

        $toDeactivate = $this->usersRemovedFromGroup($client, $activeObjectIds, $activeEmails);
        $deactivated = $toDeactivate->count();

        if (! $dryRun) {
            foreach ($toDeactivate as $user) {
                $user->update([
                    'is_active' => false,
                    'entra_synced_at' => now(),
                ]);
            }

            $client->update(['entra_synced_at' => now()]);
            $this->portalLinks->clearCache($client->id);
        }

        return new EntraSyncResult($created, $updated, $deactivated, $skipped, $errors);
    }

    /**
     * @param  list<string>  $activeObjectIds
     * @param  list<string>  $activeEmails
     * @return Collection<int, User>
     */
    private function usersRemovedFromGroup(Client $client, array $activeObjectIds, array $activeEmails): Collection
    {
        return User::query()
            ->where('client_id', $client->id)
            ->where('provisioned_by', UserProvisionSource::EntraSync)
            ->where('is_active', true)
            ->get()
            ->filter(function (User $user) use ($activeObjectIds, $activeEmails) {
                return ! in_array($user->entra_object_id, $activeObjectIds, true)
                    && ! in_array(strtolower($user->email), $activeEmails, true);
            });
    }

    /**
     * @param  array{id: string, mail: ?string, userPrincipalName: ?string}  $graphUser
     */
    private function resolveEmail(array $graphUser): ?string
    {
        $email = $graphUser['mail'] ?: $graphUser['userPrincipalName'];

        if (! is_string($email) || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        return strtolower($email);
    }

    public function syncAll(?int $clientId = null, bool $dryRun = false): array
    {
        $query = Client::query()
            ->where('is_active', true)
            ->where('entra_sync_enabled', true)
            ->whereNotNull('entra_tenant_id')
            ->whereNotNull('entra_group_id');

        if ($clientId) {
            $query->whereKey($clientId);
        }

        $results = [];

        foreach ($query->get() as $client) {
            $results[$client->id] = [
                'client' => $client->name,
                'result' => $this->syncClient($client, $dryRun),
            ];
        }

        return $results;
    }
}
