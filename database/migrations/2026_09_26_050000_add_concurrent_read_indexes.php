<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Read-path indexes for concurrent learners (progress heartbeats, catalog, enrollments).
     *
     * @var array<int, array{table: string, name: string, columns: array<int, string>}>
     */
    private array $indexes = [
        [
            'table' => 'user_lesson_progress',
            'name' => 'ulp_course_lesson_heartbeat_idx',
            'columns' => ['course_id', 'lesson_key', 'last_heartbeat_at'],
        ],
        [
            'table' => 'user_lesson_progress',
            'name' => 'ulp_course_heartbeat_idx',
            'columns' => ['course_id', 'last_heartbeat_at'],
        ],
        [
            'table' => 'enrollments',
            'name' => 'enrollments_user_status_idx',
            'columns' => ['user_id', 'status'],
        ],
        [
            'table' => 'enrollments',
            'name' => 'enrollments_user_course_idx',
            'columns' => ['user_id', 'course_id'],
        ],
        [
            'table' => 'enrollments',
            'name' => 'enrollments_course_status_idx',
            'columns' => ['course_id', 'status'],
        ],
        [
            'table' => 'enrollments',
            'name' => 'enrollments_program_status_idx',
            'columns' => ['program_id', 'status', 'course_id'],
        ],
        [
            'table' => 'enrollments',
            'name' => 'enrollments_status_submitted_idx',
            'columns' => ['status', 'submitted_at'],
        ],
        [
            'table' => 'courses',
            'name' => 'courses_program_published_idx',
            'columns' => ['program_id', 'is_published'],
        ],
        [
            'table' => 'module_resources',
            'name' => 'mod_res_standalone_sort_idx',
            'columns' => ['module_id', 'is_standalone_lesson', 'sort_order'],
        ],
        [
            'table' => 'quiz_attempts',
            'name' => 'quiz_attempts_quiz_user_idx',
            'columns' => ['quiz_id', 'user_id'],
        ],
        [
            'table' => 'lesson_materials',
            'name' => 'lesson_materials_module_usage_idx',
            'columns' => ['module_id', 'usage'],
        ],
        [
            'table' => 'users',
            'name' => 'users_role_status_idx',
            'columns' => ['role', 'status'],
        ],
        [
            'table' => 'quizzes',
            'name' => 'quizzes_course_id_idx',
            'columns' => ['course_id'],
        ],
    ];

    public function up(): void
    {
        foreach ($this->indexes as $index) {
            $this->addIndexIfMissing($index['table'], $index['name'], $index['columns']);
        }
    }

    public function down(): void
    {
        foreach (array_reverse($this->indexes) as $index) {
            $this->dropIndexIfExists($index['table'], $index['name']);
        }
    }

    /**
     * @param  array<int, string>  $columns
     */
    private function addIndexIfMissing(string $table, string $name, array $columns): void
    {
        if (! Schema::hasTable($table) || Schema::hasIndex($table, $name) || Schema::hasIndex($table, $columns)) {
            return;
        }

        foreach ($columns as $column) {
            if (! Schema::hasColumn($table, $column)) {
                return;
            }
        }

        Schema::table($table, function (Blueprint $blueprint) use ($name, $columns) {
            $blueprint->index($columns, $name);
        });
    }

    private function dropIndexIfExists(string $table, string $name): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasIndex($table, $name)) {
            return;
        }

        try {
            Schema::table($table, function (Blueprint $blueprint) use ($name) {
                $blueprint->dropIndex($name);
            });
        } catch (\Throwable $e) {
            // MySQL refuses to drop an index that is still required by a foreign key.
        }
    }
};
