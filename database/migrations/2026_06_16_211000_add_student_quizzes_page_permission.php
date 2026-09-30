<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $rows = [
            [
                'role' => 'admin',
                'path' => '/student-quizzes',
                'match_type' => 'prefix',
                'query' => null,
                'label' => 'Student quizzes',
                'sort_order' => 45,
            ],
            [
                'role' => 'instructor',
                'path' => '/student-quizzes',
                'match_type' => 'prefix',
                'query' => null,
                'label' => 'Student quizzes',
                'sort_order' => 44,
            ],
        ];

        foreach ($rows as $row) {
            $exists = DB::table('role_page_permissions')
                ->where('role', $row['role'])
                ->where('path', $row['path'])
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('role_page_permissions')->insert([
                ...$row,
                'query' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('role_page_permissions')
            ->whereIn('role', ['admin', 'instructor'])
            ->where('path', '/student-quizzes')
            ->delete();
    }
};
