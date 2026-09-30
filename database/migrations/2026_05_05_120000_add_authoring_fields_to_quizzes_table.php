<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quizzes', function (Blueprint $table) {
            $table->text('description')->nullable()->after('title');
            $table->longText('lesson_content_html')->nullable()->after('description');
            $table->json('settings_json')->nullable()->after('lesson_content_html');
        });
    }

    public function down(): void
    {
        Schema::table('quizzes', function (Blueprint $table) {
            $table->dropColumn(['description', 'lesson_content_html', 'settings_json']);
        });
    }
};
