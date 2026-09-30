<?php

namespace App\Services;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class NotificationSchemaService
{
    private const ENROLLMENT_NOTIFICATIONS_MIGRATION = '2026_06_16_120000_extend_in_app_notifications_for_enrollments';

    /**
     * Ensures enrollment notification columns exist (self-heals when migrate was not run).
     */
    public static function ensureEnrollmentNotificationColumns(): void
    {
        if (! Schema::hasTable('in_app_notifications')) {
            return;
        }

        $changed = false;

        if (Schema::hasColumn('in_app_notifications', 'announcement_id')) {
            try {
                DB::statement('ALTER TABLE in_app_notifications MODIFY announcement_id BIGINT UNSIGNED NULL');
            } catch (\Throwable) {
                // Driver may not support MODIFY; nullable FK is handled by migration when available.
            }
        }

        if (! Schema::hasColumn('in_app_notifications', 'enrollment_id')) {
            Schema::table('in_app_notifications', function (Blueprint $table) {
                $table->unsignedBigInteger('enrollment_id')->nullable()->after('announcement_id');
            });
            $changed = true;
        }

        if (! Schema::hasColumn('in_app_notifications', 'kind')) {
            Schema::table('in_app_notifications', function (Blueprint $table) {
                $table->string('kind', 64)->default('announcement')->after('enrollment_id');
            });
            $changed = true;
        }

        if (! Schema::hasColumn('in_app_notifications', 'title')) {
            Schema::table('in_app_notifications', function (Blueprint $table) {
                $table->string('title', 500)->nullable()->after('kind');
            });
            $changed = true;
        }

        if (! Schema::hasColumn('in_app_notifications', 'body')) {
            Schema::table('in_app_notifications', function (Blueprint $table) {
                $table->longText('body')->nullable()->after('title');
            });
            $changed = true;
        }

        if ($changed) {
            self::recordMigrationIfMissing();
        }
    }

    private static function recordMigrationIfMissing(): void
    {
        if (! Schema::hasTable('migrations')) {
            return;
        }

        $exists = DB::table('migrations')
            ->where('migration', self::ENROLLMENT_NOTIFICATIONS_MIGRATION)
            ->exists();

        if ($exists) {
            return;
        }

        $batch = (int) DB::table('migrations')->max('batch');

        DB::table('migrations')->insert([
            'migration' => self::ENROLLMENT_NOTIFICATIONS_MIGRATION,
            'batch' => $batch > 0 ? $batch : 1,
        ]);
    }
}
