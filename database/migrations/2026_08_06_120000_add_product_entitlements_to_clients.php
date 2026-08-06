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
            $table->json('product_entitlements')->nullable()->after('onboarding_checklist');
        });

        // Backfill: if a product mapping already exists, mark it entitled so behaviour is unchanged.
        $rows = DB::table('clients')->select([
            'id',
            'superops_account_id',
            'entra_tenant_id',
            'huntress_organization_id',
            'dropsuite_organization_id',
            'pax8_company_id',
            'pax8_sso_enabled',
        ])->get();

        $now = now()->toIso8601String();

        foreach ($rows as $row) {
            $entitlements = [];

            if (filled($row->superops_account_id)) {
                $entitlements['superops'] = ['entitled' => true, 'entitled_at' => $now, 'notes' => null];
            }
            if (filled($row->entra_tenant_id)) {
                $entitlements['m365'] = ['entitled' => true, 'entitled_at' => $now, 'notes' => null];
            }
            if (filled($row->huntress_organization_id)) {
                $entitlements['huntress'] = ['entitled' => true, 'entitled_at' => $now, 'notes' => null];
            }
            if (filled($row->dropsuite_organization_id)) {
                $entitlements['dropsuite'] = ['entitled' => true, 'entitled_at' => $now, 'notes' => null];
            }
            if (filled($row->pax8_company_id) || (bool) $row->pax8_sso_enabled) {
                $entitlements['pax8'] = ['entitled' => true, 'entitled_at' => $now, 'notes' => null];
            }

            DB::table('clients')->where('id', $row->id)->update([
                'product_entitlements' => $entitlements === [] ? null : json_encode($entitlements),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('product_entitlements');
        });
    }
};
