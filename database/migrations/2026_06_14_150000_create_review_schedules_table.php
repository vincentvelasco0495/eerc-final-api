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
            Schema::create('review_schedules', function (Blueprint $table) {
                $table->id();
                $table->string('public_id', 64)->unique();
                $table->string('name');
                $table->text('description')->nullable();
                $table->string('status', 16)->default('active');
                $table->unsignedSmallInteger('sort_order')->default(1);
                $table->unsignedInteger('student_capacity')->default(30);
                $table->foreignId('enrollment_branch_id')->nullable()->constrained('branch_enrolls')->nullOnDelete();
                $table->timestamps();
                $table->softDeletes();
            });

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
        if (! Schema::hasTable('review_schedules')) {
            return;
        }

        if (Schema::hasColumn('review_schedules', 'name')) {
            Schema::table('review_schedules', function (Blueprint $table) {
                $table->dropColumn('name');
            });
        }
    }
};
