<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('enrollments') || ! Schema::hasTable('branch_enrolls')) {
            return;
        }

        if (! Schema::hasColumn('enrollments', 'branch_enroll_id')) {
            Schema::table('enrollments', function (Blueprint $table) {
                $after = 'program_id';
                if (Schema::hasColumn('enrollments', 'learning_mode_id')) {
                    $after = 'learning_mode_id';
                } elseif (Schema::hasColumn('enrollments', 'batch_enroll_id')) {
                    $after = 'batch_enroll_id';
                }

                $table->foreignId('branch_enroll_id')
                    ->nullable()
                    ->after($after)
                    ->constrained('branch_enrolls')
                    ->nullOnDelete();
                $table->index('branch_enroll_id');
            });
        }

        $this->backfillFromStoredBranchPublicIds();
    }

    public function down(): void
    {
        if (! Schema::hasTable('enrollments') || ! Schema::hasColumn('enrollments', 'branch_enroll_id')) {
            return;
        }

        Schema::table('enrollments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('branch_enroll_id');
        });
    }

    /**
     * Copy only an already-stored branch unique ID from form_data. Never match by name
     * (so "PURE ONLINE CLASS" as a branch stays distinct from the learning-mode option).
     */
    private function backfillFromStoredBranchPublicIds(): void
    {
        \App\Services\EnrollmentSchemaService::backfillBranchEnrollIdsFromFormData();
    }
};
