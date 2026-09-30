<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('lesson_materials') || Schema::hasColumn('lesson_materials', 'usage')) {
            return;
        }

        Schema::table('lesson_materials', function (Blueprint $table) {
            $table->string('usage', 32)->default('lesson')->after('assignment_id');
            $table->index('usage');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('lesson_materials') || ! Schema::hasColumn('lesson_materials', 'usage')) {
            return;
        }

        Schema::table('lesson_materials', function (Blueprint $table) {
            $table->dropIndex(['usage']);
            $table->dropColumn('usage');
        });
    }
};
