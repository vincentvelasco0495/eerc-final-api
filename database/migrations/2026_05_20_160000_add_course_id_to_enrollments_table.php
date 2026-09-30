<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('enrollments', 'course_id')) {
            return;
        }

        Schema::table('enrollments', function (Blueprint $table) {
            $table->foreignId('course_id')->nullable()->after('program_id')->constrained('courses')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('enrollments', 'course_id')) {
            return;
        }

        Schema::table('enrollments', function (Blueprint $table) {
            $table->dropForeign(['course_id']);
            $table->dropColumn('course_id');
        });
    }
};
