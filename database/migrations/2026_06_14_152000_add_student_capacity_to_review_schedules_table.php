<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('review_schedules')) {
            return;
        }

        Schema::table('review_schedules', function (Blueprint $table) {
            if (! Schema::hasColumn('review_schedules', 'student_capacity')) {
                $table->unsignedInteger('student_capacity')->default(30)->after('sort_order');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('review_schedules') || ! Schema::hasColumn('review_schedules', 'student_capacity')) {
            return;
        }

        Schema::table('review_schedules', function (Blueprint $table) {
            $table->dropColumn('student_capacity');
        });
    }
};
