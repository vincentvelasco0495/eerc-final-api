<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('enrollments')) {
            return;
        }

        Schema::table('enrollments', function (Blueprint $table) {
            if (! Schema::hasColumn('enrollments', 'rejection_reason')) {
                $table->text('rejection_reason')->nullable()->after('status');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('enrollments')) {
            return;
        }

        Schema::table('enrollments', function (Blueprint $table) {
            if (Schema::hasColumn('enrollments', 'rejection_reason')) {
                $table->dropColumn('rejection_reason');
            }
        });
    }
};
