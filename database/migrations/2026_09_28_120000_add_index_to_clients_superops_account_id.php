<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `clients.superops_account_id` is looked up with a plain `where()` on every
     * support-ticket view for team members whose ticket isn't already scoped to
     * their own client (SuperOpsTicketService::userCanViewTicket()), and again
     * whenever a SuperOps webhook/account id needs mapping back to a Client. The
     * column had no index despite being a real, hot lookup key - see
     * docs/SYSTEM_AUDIT.md "Third Audit Pass" for the evidence trail.
     */
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->index('superops_account_id');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropIndex(['superops_account_id']);
        });
    }
};
