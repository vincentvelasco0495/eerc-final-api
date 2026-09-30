<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quizzes', function (Blueprint $table) {
            $table->unsignedSmallInteger('sort_order')->default(0)->after('module_id');
            $table->index(['module_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::table('quizzes', function (Blueprint $table) {
            $table->dropIndex(['module_id', 'sort_order']);
            $table->dropColumn('sort_order');
        });
    }
};
