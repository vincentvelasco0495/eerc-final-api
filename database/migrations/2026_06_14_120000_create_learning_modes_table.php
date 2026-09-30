<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('learning_modes')) {
            Schema::create('learning_modes', function (Blueprint $table) {
                $table->id();
                $table->string('public_id', 64)->unique();
                $table->string('name');
                $table->text('description')->nullable();
                $table->string('status', 16)->default('active');
                $table->unsignedSmallInteger('sort_order')->default(1);
                $table->timestamps();
                $table->softDeletes();
            });

            return;
        }

        Schema::table('learning_modes', function (Blueprint $table) {
            if (! Schema::hasColumn('learning_modes', 'name')) {
                $table->string('name')->nullable()->after('public_id');
            }
        });

        if (Schema::hasColumn('learning_modes', 'label') && Schema::hasColumn('learning_modes', 'name')) {
            DB::table('learning_modes')
                ->whereNull('name')
                ->update(['name' => DB::raw('label')]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('learning_modes');
    }
};
