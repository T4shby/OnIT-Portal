<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('entra_superops_sso_app_id', 36)
                ->nullable()
                ->after('entra_superops_app_id');
        });

        // This checkpoint previously meant the retired Global SSO admin Accept.
        // Force every existing client through the new customer-specific Client SSO step.
        DB::table('clients')
            ->select(['id', 'onboarding_checklist'])
            ->whereNotNull('onboarding_checklist')
            ->orderBy('id')
            ->chunkById(100, function ($clients): void {
                foreach ($clients as $client) {
                    $checklist = json_decode((string) $client->onboarding_checklist, true);

                    if (! is_array($checklist) || ! array_key_exists('superops_client_sso_configured', $checklist)) {
                        continue;
                    }

                    unset($checklist['superops_client_sso_configured']);

                    DB::table('clients')
                        ->where('id', $client->id)
                        ->update(['onboarding_checklist' => json_encode($checklist, JSON_THROW_ON_ERROR)]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('entra_superops_sso_app_id');
        });
    }
};
