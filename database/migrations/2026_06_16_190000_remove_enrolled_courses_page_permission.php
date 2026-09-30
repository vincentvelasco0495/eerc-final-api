<?php

use App\Models\RolePagePermission;
use App\Services\PagePermissionService;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        RolePagePermission::query()
            ->where('path', '/enrolled-courses')
            ->delete();

        app(PagePermissionService::class)->clearCache();
    }

    public function down(): void
    {
        $exists = RolePagePermission::query()
            ->where('role', 'student')
            ->where('path', '/enrolled-courses')
            ->where('match_type', 'exact')
            ->exists();

        if (! $exists) {
            RolePagePermission::query()->create([
                'role' => 'student',
                'path' => '/enrolled-courses',
                'match_type' => 'exact',
                'query' => null,
                'label' => 'Enrolled courses',
                'sort_order' => 10,
            ]);
        }

        app(PagePermissionService::class)->clearCache();
    }
};
