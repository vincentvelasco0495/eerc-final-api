<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('enrollments') || ! Schema::hasTable('package_enrolls')) {
            return;
        }

        if (! Schema::hasColumn('enrollments', 'package_enroll_id')) {
            Schema::table('enrollments', function (Blueprint $table) {
                $after = 'program_id';
                if (Schema::hasColumn('enrollments', 'honor_award_discount_id')) {
                    $after = 'honor_award_discount_id';
                } elseif (Schema::hasColumn('enrollments', 'review_schedule_id')) {
                    $after = 'review_schedule_id';
                } elseif (Schema::hasColumn('enrollments', 'branch_enroll_id')) {
                    $after = 'branch_enroll_id';
                } elseif (Schema::hasColumn('enrollments', 'learning_mode_id')) {
                    $after = 'learning_mode_id';
                } elseif (Schema::hasColumn('enrollments', 'batch_enroll_id')) {
                    $after = 'batch_enroll_id';
                }

                $table->foreignId('package_enroll_id')
                    ->nullable()
                    ->after($after)
                    ->constrained('package_enrolls')
                    ->nullOnDelete();
                $table->index('package_enroll_id');
            });
        }

        \App\Services\EnrollmentSchemaService::backfillPackageEnrollIdsFromFormData();
    }

    public function down(): void
    {
        if (! Schema::hasTable('enrollments') || ! Schema::hasColumn('enrollments', 'package_enroll_id')) {
            return;
        }

        Schema::table('enrollments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('package_enroll_id');
        });
    }
};
