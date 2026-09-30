<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('in_app_notifications')) {
            return;
        }

        Schema::table('in_app_notifications', function (Blueprint $table) {
            if (! Schema::hasColumn('in_app_notifications', 'enrollment_id')) {
                $table->foreignId('enrollment_id')->nullable()->after('announcement_id')
                    ->constrained('enrollments')->nullOnDelete();
            }
            if (! Schema::hasColumn('in_app_notifications', 'kind')) {
                $table->string('kind', 64)->default('announcement')->after('enrollment_id');
            }
            if (! Schema::hasColumn('in_app_notifications', 'title')) {
                $table->string('title', 500)->nullable()->after('kind');
            }
            if (! Schema::hasColumn('in_app_notifications', 'body')) {
                $table->longText('body')->nullable()->after('title');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('in_app_notifications')) {
            return;
        }

        Schema::table('in_app_notifications', function (Blueprint $table) {
            if (Schema::hasColumn('in_app_notifications', 'enrollment_id')) {
                $table->dropConstrainedForeignId('enrollment_id');
            }
            if (Schema::hasColumn('in_app_notifications', 'kind')) {
                $table->dropColumn('kind');
            }
            if (Schema::hasColumn('in_app_notifications', 'title')) {
                $table->dropColumn('title');
            }
            if (Schema::hasColumn('in_app_notifications', 'body')) {
                $table->dropColumn('body');
            }
        });
    }
};
