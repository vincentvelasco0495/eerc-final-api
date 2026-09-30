<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('enrollments') || ! Schema::hasTable('honor_award_discounts')) {
            return;
        }

        if (! Schema::hasColumn('enrollments', 'honor_award_discount_id')) {
            Schema::table('enrollments', function (Blueprint $table) {
                $after = 'program_id';
                if (Schema::hasColumn('enrollments', 'review_schedule_id')) {
                    $after = 'review_schedule_id';
                } elseif (Schema::hasColumn('enrollments', 'branch_enroll_id')) {
                    $after = 'branch_enroll_id';
                } elseif (Schema::hasColumn('enrollments', 'learning_mode_id')) {
                    $after = 'learning_mode_id';
                } elseif (Schema::hasColumn('enrollments', 'batch_enroll_id')) {
                    $after = 'batch_enroll_id';
                }

                $table->foreignId('honor_award_discount_id')
                    ->nullable()
                    ->after($after)
                    ->constrained('honor_award_discounts')
                    ->nullOnDelete();
                $table->index('honor_award_discount_id');
            });
        }

        \App\Services\EnrollmentSchemaService::backfillHonorAwardDiscountIdsFromFormData();
    }

    public function down(): void
    {
        if (! Schema::hasTable('enrollments') || ! Schema::hasColumn('enrollments', 'honor_award_discount_id')) {
            return;
        }

        Schema::table('enrollments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('honor_award_discount_id');
        });
    }
};
