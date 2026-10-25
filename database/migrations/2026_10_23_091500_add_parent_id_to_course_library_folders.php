<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('course_library_folders')) {
            return;
        }

        Schema::table('course_library_folders', function (Blueprint $table) {
            if (! Schema::hasColumn('course_library_folders', 'parent_id')) {
                $table->foreignId('parent_id')
                    ->nullable()
                    ->after('tab_id')
                    ->constrained('course_library_folders')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('course_library_folders') || ! Schema::hasColumn('course_library_folders', 'parent_id')) {
            return;
        }

        Schema::table('course_library_folders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_id');
        });
    }
};
