<?php

use App\Models\RolePagePermission;
use App\Services\PagePermissionService;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['admin', 'instructor'] as $role) {
            $exists = RolePagePermission::query()
                ->where('role', $role)
                ->where('path', '/feedback')
                ->where('match_type', 'prefix')
                ->exists();

            if (! $exists) {
                RolePagePermission::query()->create([
                    'role' => $role,
                    'path' => '/feedback',
                    'match_type' => 'prefix',
                    'query' => null,
                    'label' => 'Contact feedback',
                    'sort_order' => 42,
                ]);
            }
        }

        app(PagePermissionService::class)->clearCache();
    }

    public function down(): void
    {
        RolePagePermission::query()
            ->where('path', '/feedback')
            ->where('match_type', 'prefix')
            ->whereIn('role', ['admin', 'instructor'])
            ->delete();

        app(PagePermissionService::class)->clearCache();
    }
};
