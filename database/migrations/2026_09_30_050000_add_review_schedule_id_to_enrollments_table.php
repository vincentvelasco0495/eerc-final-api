<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('enrollments') || ! Schema::hasTable('review_schedules')) {
            return;
        }

        if (! Schema::hasColumn('enrollments', 'review_schedule_id')) {
            Schema::table('enrollments', function (Blueprint $table) {
                $after = 'program_id';
                if (Schema::hasColumn('enrollments', 'branch_enroll_id')) {
                    $after = 'branch_enroll_id';
                } elseif (Schema::hasColumn('enrollments', 'learning_mode_id')) {
                    $after = 'learning_mode_id';
                } elseif (Schema::hasColumn('enrollments', 'batch_enroll_id')) {
                    $after = 'batch_enroll_id';
                }

                $table->foreignId('review_schedule_id')
                    ->nullable()
                    ->after($after)
                    ->constrained('review_schedules')
                    ->nullOnDelete();
                $table->index('review_schedule_id');
            });
        }

        \App\Services\EnrollmentSchemaService::backfillReviewScheduleIdsFromFormData();
    }

    public function down(): void
    {
        if (! Schema::hasTable('enrollments') || ! Schema::hasColumn('enrollments', 'review_schedule_id')) {
            return;
        }

        Schema::table('enrollments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('review_schedule_id');
        });
    }
};
