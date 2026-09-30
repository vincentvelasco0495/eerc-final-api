<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('review_schedules')) {
            return;
        }

        Schema::table('review_schedules', function (Blueprint $table) {
            if (! Schema::hasColumn('review_schedules', 'name')) {
                $table->string('name')->nullable()->after('public_id');
            }
        });

        if (Schema::hasColumn('review_schedules', 'label') && Schema::hasColumn('review_schedules', 'name')) {
            DB::table('review_schedules')
                ->whereNull('name')
                ->update(['name' => DB::raw('label')]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('review_schedules') || ! Schema::hasColumn('review_schedules', 'name')) {
            return;
        }

        Schema::table('review_schedules', function (Blueprint $table) {
            $table->dropColumn('name');
        });
    }
};
