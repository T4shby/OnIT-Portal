<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('entra_tenant_id', 36)->nullable()->after('superops_sso_enabled');
            $table->string('entra_group_id', 36)->nullable()->after('entra_tenant_id');
            $table->boolean('entra_sync_enabled')->default(false)->after('entra_group_id');
            $table->timestamp('entra_synced_at')->nullable()->after('entra_sync_enabled');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('provisioned_by', 20)->default('manual')->after('is_active');
            $table->timestamp('entra_synced_at')->nullable()->after('provisioned_by');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['provisioned_by', 'entra_synced_at']);
        });

        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn([
                'entra_tenant_id',
                'entra_group_id',
                'entra_sync_enabled',
                'entra_synced_at',
            ]);
        });
    }
};
