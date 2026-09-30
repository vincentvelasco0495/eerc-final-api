<?php

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
            ->where('path', '/course-details')
            ->exists();

        if ($exists) {
            return;
        }

        DB::table('role_page_permissions')->insert([
            'role' => 'admin',
            'path' => '/course-details',
            'match_type' => 'prefix',
            'query' => null,
            'label' => 'Course details',
            'sort_order' => 53,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('role_page_permissions')) {
            return;
        }

        DB::table('role_page_permissions')
            ->where('role', 'admin')
            ->where('path', '/course-details')
            ->delete();
    }
};
