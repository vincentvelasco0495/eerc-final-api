<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('course_library_tabs') && ! Schema::hasColumn('course_library_tabs', 'tab_type')) {
            Schema::table('course_library_tabs', function (Blueprint $table) {
                $table->string('tab_type', 32)->default('folder')->after('slug');
            });
        }

        if (Schema::hasTable('courses') && ! Schema::hasColumn('courses', 'library_tabs_seeded')) {
            Schema::table('courses', function (Blueprint $table) {
                $table->boolean('library_tabs_seeded')->default(false);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('course_library_tabs') && Schema::hasColumn('course_library_tabs', 'tab_type')) {
            Schema::table('course_library_tabs', function (Blueprint $table) {
                $table->dropColumn('tab_type');
            });
        }

        if (Schema::hasTable('courses') && Schema::hasColumn('courses', 'library_tabs_seeded')) {
            Schema::table('courses', function (Blueprint $table) {
                $table->dropColumn('library_tabs_seeded');
            });
        }
    }
};
