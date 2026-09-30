<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('role_page_permissions')
            ->whereIn('role', ['admin', 'instructor'])
            ->where('path', '/assignment')
            ->where('match_type', 'exact')
            ->update(['match_type' => 'prefix']);
    }

    public function down(): void
    {
        DB::table('role_page_permissions')
            ->whereIn('role', ['admin', 'instructor'])
            ->where('path', '/assignment')
            ->where('match_type', 'prefix')
            ->update(['match_type' => 'exact']);
    }
};
