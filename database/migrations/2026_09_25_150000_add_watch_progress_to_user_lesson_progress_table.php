<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('user_lesson_progress')) {
            return;
        }

        Schema::table('user_lesson_progress', function (Blueprint $table) {
            if (! Schema::hasColumn('user_lesson_progress', 'progress_percent')) {
                $table->unsignedTinyInteger('progress_percent')->default(0)->after('lesson_key');
            }
            if (! Schema::hasColumn('user_lesson_progress', 'last_position_seconds')) {
                $table->unsignedInteger('last_position_seconds')->default(0)->after('progress_percent');
            }
            if (! Schema::hasColumn('user_lesson_progress', 'last_heartbeat_at')) {
                $table->timestamp('last_heartbeat_at')->nullable()->after('completed_at');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('user_lesson_progress')) {
            return;
        }

        Schema::table('user_lesson_progress', function (Blueprint $table) {
            if (Schema::hasColumn('user_lesson_progress', 'last_heartbeat_at')) {
                $table->dropColumn('last_heartbeat_at');
            }
            if (Schema::hasColumn('user_lesson_progress', 'last_position_seconds')) {
                $table->dropColumn('last_position_seconds');
            }
            if (Schema::hasColumn('user_lesson_progress', 'progress_percent')) {
                $table->dropColumn('progress_percent');
            }
        });
    }
};
