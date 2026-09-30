<?php

use App\Services\PagePermissionService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('role_page_permissions')
            ->where('role', 'student')
            ->where('path', '/assignments')
            ->where('match_type', 'exact')
            ->update(['match_type' => 'prefix']);

        app(PagePermissionService::class)->clearCache('student');
    }

    public function down(): void
    {
        DB::table('role_page_permissions')
            ->where('role', 'student')
            ->where('path', '/assignments')
            ->where('match_type', 'prefix')
            ->update(['match_type' => 'exact']);

        app(PagePermissionService::class)->clearCache('student');
    }
};
