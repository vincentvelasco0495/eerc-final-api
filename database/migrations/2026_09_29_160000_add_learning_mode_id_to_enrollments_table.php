<?php

use App\Models\Enrollment;
use App\Models\LearningMode;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('enrollments') || ! Schema::hasTable('learning_modes')) {
            return;
        }

        if (! Schema::hasColumn('enrollments', 'learning_mode_id')) {
            Schema::table('enrollments', function (Blueprint $table) {
                $after = Schema::hasColumn('enrollments', 'batch_enroll_id')
                    ? 'batch_enroll_id'
                    : 'program_id';

                $table->foreignId('learning_mode_id')
                    ->nullable()
                    ->after($after)
                    ->constrained('learning_modes')
                    ->nullOnDelete();
                $table->index('learning_mode_id');
            });
        }

        $this->backfillFromStoredLearningModePublicIds();
    }

    public function down(): void
    {
        if (! Schema::hasTable('enrollments') || ! Schema::hasColumn('enrollments', 'learning_mode_id')) {
            return;
        }

        Schema::table('enrollments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('learning_mode_id');
        });
    }

    /**
     * Copy only an already-stored learning-mode unique ID from form_data. Never match by name.
     */
    private function backfillFromStoredLearningModePublicIds(): void
    {
        if (! Schema::hasColumn('enrollments', 'form_data')) {
            return;
        }

        $modesByPublicId = LearningMode::query()
            ->get(['id', 'public_id'])
            ->keyBy('public_id');

        Enrollment::query()
            ->whereNull('learning_mode_id')
            ->whereNotNull('form_data')
            ->orderBy('id')
            ->each(function (Enrollment $enrollment) use ($modesByPublicId) {
                $formData = $enrollment->form_data;
                if (! is_array($formData)) {
                    return;
                }

                $modePublicId = trim((string) ($formData['learningModeId'] ?? ''));
                if ($modePublicId === '') {
                    return;
                }

                $mode = $modesByPublicId->get($modePublicId);
                if ($mode === null) {
                    return;
                }

                $enrollment->forceFill(['learning_mode_id' => $mode->id])->saveQuietly();
            });
    }
};
