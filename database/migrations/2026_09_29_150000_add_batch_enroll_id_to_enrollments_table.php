<?php

use App\Models\BatchEnroll;
use App\Models\Enrollment;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('enrollments') || ! Schema::hasTable('batch_enrolls')) {
            return;
        }

        if (! Schema::hasColumn('enrollments', 'batch_enroll_id')) {
            Schema::table('enrollments', function (Blueprint $table) {
                $table->foreignId('batch_enroll_id')
                    ->nullable()
                    ->after('program_id')
                    ->constrained('batch_enrolls')
                    ->nullOnDelete();
                $table->index(['batch_enroll_id', 'program_id']);
            });
        }

        $this->backfillFromStoredBatchPublicIds();
    }

    public function down(): void
    {
        if (! Schema::hasTable('enrollments') || ! Schema::hasColumn('enrollments', 'batch_enroll_id')) {
            return;
        }

        Schema::table('enrollments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('batch_enroll_id');
        });
    }

    /**
     * Copy only an already-stored batch unique ID from form_data. Never match by batch name.
     */
    private function backfillFromStoredBatchPublicIds(): void
    {
        if (! Schema::hasColumn('enrollments', 'form_data')) {
            return;
        }

        $batchesByPublicId = BatchEnroll::query()
            ->get(['id', 'public_id', 'program_id'])
            ->keyBy('public_id');

        Enrollment::query()
            ->whereNull('batch_enroll_id')
            ->whereNotNull('form_data')
            ->orderBy('id')
            ->each(function (Enrollment $enrollment) use ($batchesByPublicId) {
                $formData = $enrollment->form_data;
                if (! is_array($formData)) {
                    return;
                }

                $batchPublicId = trim((string) ($formData['batchEnrollId'] ?? ''));
                if ($batchPublicId === '') {
                    return;
                }

                $batch = $batchesByPublicId->get($batchPublicId);
                if ($batch === null) {
                    return;
                }

                if ((int) $batch->program_id !== (int) $enrollment->program_id) {
                    return;
                }

                $enrollment->forceFill(['batch_enroll_id' => $batch->id])->saveQuietly();
            });
    }
};
