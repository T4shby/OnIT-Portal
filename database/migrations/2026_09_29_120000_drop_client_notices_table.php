<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Retired feature (docs/SYSTEM_AUDIT.md, F9): staff-authored client notices
     * were only ever read by their own admin CRUD pages - no customer-facing view
     * showed them - so the repo owner chose to retire rather than surface them.
     * down() recreates the table exactly as 0001_01_01_000001_create_portal_tables
     * built it (data is not restored).
     */
    public function up(): void
    {
        Schema::dropIfExists('client_notices');
    }

    public function down(): void
    {
        Schema::create('client_notices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('body');
            $table->timestamp('published_at')->nullable()->index();
            $table->timestamp('expires_at')->nullable()->index();
            $table->boolean('is_active')->default(true)->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['client_id', 'is_active']);
        });
    }
};
