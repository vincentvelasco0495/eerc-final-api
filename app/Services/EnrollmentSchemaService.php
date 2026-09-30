<?php

namespace App\Services;

use App\Models\BranchEnroll;
use App\Models\Enrollment;
use App\Models\HonorAwardDiscount;
use App\Models\PackageEnroll;
use App\Models\ReviewSchedule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class EnrollmentSchemaService
{
    private const FORM_DATA_MIGRATION = '2026_06_14_180000_add_form_data_to_enrollments_table';

    private const REJECTION_REASON_MIGRATION = '2026_06_16_140000_add_rejection_reason_to_enrollments_table';

    private const BATCH_ENROLL_ID_MIGRATION = '2026_09_29_150000_add_batch_enroll_id_to_enrollments_table';

    private const LEARNING_MODE_ID_MIGRATION = '2026_09_29_160000_add_learning_mode_id_to_enrollments_table';

    private const BRANCH_ENROLL_ID_MIGRATION = '2026_09_30_040000_add_branch_enroll_id_to_enrollments_table';

    private const REVIEW_SCHEDULE_ID_MIGRATION = '2026_09_30_050000_add_review_schedule_id_to_enrollments_table';

    private const HONOR_AWARD_DISCOUNT_ID_MIGRATION = '2026_09_30_060000_add_honor_award_discount_id_to_enrollments_table';

    private const PACKAGE_ENROLL_ID_MIGRATION = '2026_09_30_070000_add_package_enroll_id_to_enrollments_table';

    /**
     * Ensures enrollment wizard columns exist (self-heals when migrate was not run).
     */
    public static function ensureFormDataColumns(): void
    {
        if (! Schema::hasTable('enrollments')) {
            return;
        }

        $changed = false;

        if (! Schema::hasColumn('enrollments', 'form_data')) {
            Schema::table('enrollments', function (Blueprint $table) {
                $table->json('form_data')->nullable();
            });
            $changed = true;
        }

        if (! Schema::hasColumn('enrollments', 'documents')) {
            Schema::table('enrollments', function (Blueprint $table) {
                $table->json('documents')->nullable();
            });
            $changed = true;
        }

        if ($changed) {
            self::recordMigrationIfMissing(self::FORM_DATA_MIGRATION);
        }
    }

    public static function ensureBatchEnrollIdColumn(): void
    {
        if (! Schema::hasTable('enrollments') || ! Schema::hasTable('batch_enrolls')) {
            return;
        }

        if (Schema::hasColumn('enrollments', 'batch_enroll_id')) {
            return;
        }

        Schema::table('enrollments', function (Blueprint $table) {
            $table->foreignId('batch_enroll_id')
                ->nullable()
                ->after('program_id')
                ->constrained('batch_enrolls')
                ->nullOnDelete();
            $table->index(['batch_enroll_id', 'program_id']);
        });

        self::recordMigrationIfMissing(self::BATCH_ENROLL_ID_MIGRATION);
    }

    public static function ensureLearningModeIdColumn(): void
    {
        if (! Schema::hasTable('enrollments') || ! Schema::hasTable('learning_modes')) {
            return;
        }

        if (Schema::hasColumn('enrollments', 'learning_mode_id')) {
            return;
        }

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

        self::recordMigrationIfMissing(self::LEARNING_MODE_ID_MIGRATION);
    }

    public static function ensureBranchEnrollIdColumn(): void
    {
        if (! Schema::hasTable('enrollments') || ! Schema::hasTable('branch_enrolls')) {
            return;
        }

        if (Schema::hasColumn('enrollments', 'branch_enroll_id')) {
            self::backfillBranchEnrollIdsFromFormData();

            return;
        }

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

        self::recordMigrationIfMissing(self::BRANCH_ENROLL_ID_MIGRATION);
        self::backfillBranchEnrollIdsFromFormData();
    }

    /**
     * Copy only an already-stored branch unique ID from form_data. Never match by name.
     */
    public static function backfillBranchEnrollIdsFromFormData(): void
    {
        if (! Schema::hasTable('enrollments')
            || ! Schema::hasTable('branch_enrolls')
            || ! Schema::hasColumn('enrollments', 'branch_enroll_id')
            || ! Schema::hasColumn('enrollments', 'form_data')
        ) {
            return;
        }

        $branchesByPublicId = BranchEnroll::query()
            ->get(['id', 'public_id'])
            ->keyBy('public_id');

        Enrollment::query()
            ->whereNull('branch_enroll_id')
            ->whereNotNull('form_data')
            ->orderBy('id')
            ->each(function (Enrollment $enrollment) use ($branchesByPublicId) {
                $formData = $enrollment->form_data;
                if (! is_array($formData)) {
                    return;
                }

                $branchPublicId = trim((string) ($formData['branchEnrollId'] ?? ''));
                if ($branchPublicId === '') {
                    return;
                }

                $branch = $branchesByPublicId->get($branchPublicId);
                if ($branch === null) {
                    return;
                }

                $enrollment->forceFill(['branch_enroll_id' => $branch->id])->saveQuietly();
            });
    }

    public static function ensureReviewScheduleIdColumn(): void
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

            self::recordMigrationIfMissing(self::REVIEW_SCHEDULE_ID_MIGRATION);
        }

        self::backfillReviewScheduleIdsFromFormData();
    }

    /**
     * Copy only an already-stored schedule unique ID from form_data.
     * Verifies the schedule's associated branch when a branch ID is also stored. Never match by label or time.
     */
    public static function backfillReviewScheduleIdsFromFormData(): void
    {
        if (! Schema::hasTable('enrollments')
            || ! Schema::hasTable('review_schedules')
            || ! Schema::hasColumn('enrollments', 'review_schedule_id')
            || ! Schema::hasColumn('enrollments', 'form_data')
        ) {
            return;
        }

        $schedulesByPublicId = ReviewSchedule::query()
            ->get(['id', 'public_id', 'enrollment_branch_id'])
            ->keyBy('public_id');
        $branchesByPublicId = BranchEnroll::query()
            ->get(['id', 'public_id'])
            ->keyBy('public_id');

        Enrollment::query()
            ->whereNull('review_schedule_id')
            ->whereNotNull('form_data')
            ->orderBy('id')
            ->each(function (Enrollment $enrollment) use ($schedulesByPublicId, $branchesByPublicId) {
                $formData = $enrollment->form_data;
                if (! is_array($formData)) {
                    return;
                }

                $schedulePublicId = trim((string) ($formData['reviewScheduleId'] ?? ''));
                if ($schedulePublicId === '') {
                    return;
                }

                $schedule = $schedulesByPublicId->get($schedulePublicId);
                if ($schedule === null) {
                    return;
                }

                $branchPublicId = trim((string) ($formData['branchEnrollId'] ?? ''));
                if ($branchPublicId !== '') {
                    $branch = $branchesByPublicId->get($branchPublicId);
                    if ($branch === null || (int) $schedule->enrollment_branch_id !== (int) $branch->id) {
                        return;
                    }
                } elseif ($enrollment->branch_enroll_id
                    && $schedule->enrollment_branch_id
                    && (int) $enrollment->branch_enroll_id !== (int) $schedule->enrollment_branch_id
                ) {
                    return;
                }

                $enrollment->forceFill(['review_schedule_id' => $schedule->id])->saveQuietly();
            });
    }

    public static function ensureHonorAwardDiscountIdColumn(): void
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

            self::recordMigrationIfMissing(self::HONOR_AWARD_DISCOUNT_ID_MIGRATION);
        }

        self::backfillHonorAwardDiscountIdsFromFormData();
    }

    /**
     * Copy only an already-stored honors/awards/discount unique ID from form_data.
     * Never match by name, and never treat missing/null as the "None" option.
     */
    public static function backfillHonorAwardDiscountIdsFromFormData(): void
    {
        if (! Schema::hasTable('enrollments')
            || ! Schema::hasTable('honor_award_discounts')
            || ! Schema::hasColumn('enrollments', 'honor_award_discount_id')
            || ! Schema::hasColumn('enrollments', 'form_data')
        ) {
            return;
        }

        $optionsByPublicId = HonorAwardDiscount::query()
            ->get(['id', 'public_id'])
            ->keyBy('public_id');

        Enrollment::query()
            ->whereNull('honor_award_discount_id')
            ->whereNotNull('form_data')
            ->orderBy('id')
            ->each(function (Enrollment $enrollment) use ($optionsByPublicId) {
                $formData = $enrollment->form_data;
                if (! is_array($formData)) {
                    return;
                }

                $optionPublicId = trim((string) ($formData['honorAwardDiscountId'] ?? ''));
                if ($optionPublicId === '') {
                    return;
                }

                $option = $optionsByPublicId->get($optionPublicId);
                if ($option === null) {
                    return;
                }

                $enrollment->forceFill(['honor_award_discount_id' => $option->id])->saveQuietly();
            });
    }

    public static function ensurePackageEnrollIdColumn(): void
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

            self::recordMigrationIfMissing(self::PACKAGE_ENROLL_ID_MIGRATION);
        }

        self::backfillPackageEnrollIdsFromFormData();
    }

    /**
     * Copy only an already-stored package unique ID from form_data.
     * Never match by name or similar wording, and never infer from honors, discounts, or payments.
     */
    public static function backfillPackageEnrollIdsFromFormData(): void
    {
        if (! Schema::hasTable('enrollments')
            || ! Schema::hasTable('package_enrolls')
            || ! Schema::hasColumn('enrollments', 'package_enroll_id')
            || ! Schema::hasColumn('enrollments', 'form_data')
        ) {
            return;
        }

        $packagesByPublicId = PackageEnroll::query()
            ->get(['id', 'public_id'])
            ->keyBy('public_id');

        Enrollment::query()
            ->whereNull('package_enroll_id')
            ->whereNotNull('form_data')
            ->orderBy('id')
            ->each(function (Enrollment $enrollment) use ($packagesByPublicId) {
                $formData = $enrollment->form_data;
                if (! is_array($formData)) {
                    return;
                }

                $packagePublicId = trim((string) ($formData['packageEnrollId'] ?? $formData['package_enroll_id'] ?? ''));
                if ($packagePublicId === '') {
                    return;
                }

                $package = $packagesByPublicId->get($packagePublicId);
                if ($package === null) {
                    return;
                }

                $enrollment->forceFill(['package_enroll_id' => $package->id])->saveQuietly();
            });
    }

    public static function ensureRejectionReasonColumn(): void
    {
        if (! Schema::hasTable('enrollments')) {
            return;
        }

        if (Schema::hasColumn('enrollments', 'rejection_reason')) {
            return;
        }

        Schema::table('enrollments', function (Blueprint $table) {
            $table->text('rejection_reason')->nullable()->after('status');
        });

        self::recordMigrationIfMissing(self::REJECTION_REASON_MIGRATION);
    }

    private static function recordMigrationIfMissing(string $migration): void
    {
        if (! Schema::hasTable('migrations')) {
            return;
        }

        $exists = DB::table('migrations')
            ->where('migration', $migration)
            ->exists();

        if ($exists) {
            return;
        }

        $batch = (int) DB::table('migrations')->max('batch');

        DB::table('migrations')->insert([
            'migration' => $migration,
            'batch' => $batch > 0 ? $batch : 1,
        ]);
    }
}
