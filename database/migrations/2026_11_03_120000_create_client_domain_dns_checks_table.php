<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_domain_dns_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_domain_id')->constrained('client_domains')->cascadeOnDelete();
            $table->foreignId('checked_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source', 32)->default('manual');
            $table->string('overall', 16);
            $table->string('overall_extended', 16)->nullable();
            $table->string('provider_key', 32)->nullable();
            $table->json('result');
            $table->timestamp('checked_at');
            $table->timestamps();

            $table->index(['client_domain_id', 'checked_at']);
            $table->index(['client_id', 'checked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_domain_dns_checks');
    }
};
