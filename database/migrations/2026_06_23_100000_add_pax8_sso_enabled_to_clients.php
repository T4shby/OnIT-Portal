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
            $table->boolean('pax8_sso_enabled')->default(false)->after('pax8_company_id');
        });

        DB::table('clients')
            ->whereNotNull('pax8_company_id')
            ->where('pax8_company_id', '!=', '')
            ->update(['pax8_sso_enabled' => true]);
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('pax8_sso_enabled');
        });
    }
};
