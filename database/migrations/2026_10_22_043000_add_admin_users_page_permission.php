<?php

use App\Services\PagePermissionService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('role_page_permissions')) {
            return;
        }

        $exists = DB::table('role_page_permissions')
            ->where('role', 'admin')
            ->where('path', '/setting-admin')
            ->exists();

        if (! $exists) {
            DB::table('role_page_permissions')->insert([
                'role' => 'admin',
                'path' => '/setting-admin',
                'match_type' => 'prefix',
                'query' => null,
                'label' => 'Admin users',
                'sort_order' => 38,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        app(PagePermissionService::class)->clearCache('admin');
    }

    public function down(): void
    {
        if (! Schema::hasTable('role_page_permissions')) {
            return;
        }

        DB::table('role_page_permissions')
            ->where('role', 'admin')
            ->where('path', '/setting-admin')
            ->delete();

        app(PagePermissionService::class)->clearCache('admin');
    }
};
