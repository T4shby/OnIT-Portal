<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('superops_account_id')->nullable()->after('slug');
            $table->boolean('superops_sso_enabled')->default(false)->after('superops_account_id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('superops_user_id')->nullable()->after('entra_object_id');
            $table->text('microsoft_tokens')->nullable()->after('superops_user_id');
            $table->timestamp('superops_synced_at')->nullable()->after('last_login_at');
        });

        Schema::table('portal_links', function (Blueprint $table) {
            $table->string('link_type', 50)->default('external')->after('url');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn(['superops_account_id', 'superops_sso_enabled']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['superops_user_id', 'microsoft_tokens', 'superops_synced_at']);
        });

        Schema::table('portal_links', function (Blueprint $table) {
            $table->dropColumn('link_type');
        });
    }
};
