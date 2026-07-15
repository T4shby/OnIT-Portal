<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')
            ->where('role', 'client_user')
            ->update(['role' => 'client_requester']);

        DB::table('portal_links')
            ->where('required_role', 'client_user')
            ->update(['required_role' => 'client_requester']);
    }

    public function down(): void
    {
        DB::table('users')
            ->where('role', 'client_requester')
            ->update(['role' => 'client_user']);

        DB::table('portal_links')
            ->where('required_role', 'client_requester')
            ->update(['required_role' => 'client_user']);
    }
};
