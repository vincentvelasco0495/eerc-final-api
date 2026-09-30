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
        if (! Schema::hasTable('learning_modes') || ! Schema::hasColumn('learning_modes', 'name')) {
            return;
        }

        Schema::table('learning_modes', function (Blueprint $table) {
            $table->dropColumn('name');
        });
    }
};
