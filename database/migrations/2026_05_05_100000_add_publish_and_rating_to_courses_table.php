<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->boolean('is_published')->default(true)->after('preview_completed');
            $table->decimal('average_rating', 3, 2)->nullable()->after('is_published');
        });
    }

    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->dropColumn(['is_published', 'average_rating']);
        });
    }
};
