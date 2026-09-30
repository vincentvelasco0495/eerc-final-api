<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('enrollments', 'program_id')) {
            return;
        }

        Schema::table('enrollments', function (Blueprint $table) {
            $table->foreignId('program_id')->nullable()->after('user_id');
        });

        DB::table('enrollments')->orderBy('id')->each(function (object $enrollment) {
            $programId = DB::table('courses')->where('id', $enrollment->course_id)->value('program_id');
            if ($programId !== null) {
                DB::table('enrollments')->where('id', $enrollment->id)->update(['program_id' => $programId]);
            }
        });

        DB::table('enrollments')->whereNull('program_id')->delete();

        try {
            DB::statement('ALTER TABLE enrollments DROP FOREIGN KEY enrollments_course_id_foreign');
        } catch (\Throwable) {
            // Legacy schemas may not have this FK.
        }
        try {
            DB::statement('ALTER TABLE enrollments DROP INDEX enrollments_course_id_foreign');
        } catch (\Throwable) {
            // Index may not exist separately.
        }

        Schema::table('enrollments', function (Blueprint $table) {
            $table->dropColumn('course_id');
        });

        DB::statement('ALTER TABLE enrollments MODIFY program_id BIGINT UNSIGNED NOT NULL');

        Schema::table('enrollments', function (Blueprint $table) {
            $table->foreign('program_id')->references('id')->on('programs')->cascadeOnUpdate()->cascadeOnDelete();
            $table->index(['user_id', 'program_id']);
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('enrollments', 'program_id')) {
            return;
        }

        Schema::table('enrollments', function (Blueprint $table) {
            $table->dropForeign(['program_id']);
            $table->dropIndex(['user_id', 'program_id']);
        });

        Schema::table('enrollments', function (Blueprint $table) {
            $table->foreignId('course_id')->nullable()->after('user_id');
        });

        DB::table('enrollments')->orderBy('id')->each(function (object $enrollment) {
            $courseId = DB::table('courses')
                ->where('program_id', $enrollment->program_id)
                ->orderBy('id')
                ->value('id');

            if ($courseId !== null) {
                DB::table('enrollments')->where('id', $enrollment->id)->update(['course_id' => $courseId]);
            }
        });

        Schema::table('enrollments', function (Blueprint $table) {
            $table->dropColumn('program_id');
        });

        DB::statement('ALTER TABLE enrollments MODIFY course_id BIGINT UNSIGNED NOT NULL');

        Schema::table('enrollments', function (Blueprint $table) {
            $table->index('course_id', 'enrollments_course_id_foreign');
            $table->index(['user_id', 'course_id']);
        });
    }
};
