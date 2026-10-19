<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('primary_domain', 255)->nullable()->after('slug');
        });

        Schema::create('client_domains', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('domain', 255);
            $table->boolean('is_primary')->default(false);
            $table->json('dkim_selectors')->nullable();
            $table->string('source', 32)->default('manual');
            $table->timestamp('last_checked_at')->nullable();
            $table->json('last_result')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['client_id', 'domain']);
            $table->index(['client_id', 'is_primary']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_domains');

        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('primary_domain');
        });
    }
};
