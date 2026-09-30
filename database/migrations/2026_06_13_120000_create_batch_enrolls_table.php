<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('batch_enrolls')) {
            Schema::create('batch_enrolls', function (Blueprint $table) {
                $table->id();
                $table->string('public_id', 64)->unique();
                $table->foreignId('program_id')->constrained('programs')->cascadeOnDelete();
                $table->string('name');
                $table->string('tentative_start')->nullable();
                $table->text('description')->nullable();
                $table->string('status', 16)->default('active');
                $table->unsignedSmallInteger('sort_order')->default(1);
                $table->timestamps();
                $table->softDeletes();
            });

            return;
        }

        Schema::table('batch_enrolls', function (Blueprint $table) {
            if (! Schema::hasColumn('batch_enrolls', 'program_id')) {
                $table->foreignId('program_id')
                    ->nullable()
                    ->after('public_id')
                    ->constrained('programs')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('batch_enrolls', 'name')) {
                $table->string('name')->nullable()->after('public_id');
            }

            if (! Schema::hasColumn('batch_enrolls', 'tentative_start')) {
                $table->string('tentative_start')->nullable()->after('name');
            }
        });

        if (Schema::hasColumn('batch_enrolls', 'label') && Schema::hasColumn('batch_enrolls', 'name')) {
            DB::table('batch_enrolls')
                ->whereNull('name')
                ->update(['name' => DB::raw('label')]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('batch_enrolls')) {
            return;
        }

        Schema::table('batch_enrolls', function (Blueprint $table) {
            if (Schema::hasColumn('batch_enrolls', 'program_id')) {
                $table->dropConstrainedForeignId('program_id');
            }
            if (Schema::hasColumn('batch_enrolls', 'name')) {
                $table->dropColumn('name');
            }
            if (Schema::hasColumn('batch_enrolls', 'tentative_start')) {
                $table->dropColumn('tentative_start');
            }
        });
    }
};
