<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_lesson_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_id')->constrained('courses')->cascadeOnDelete();
            // `lesson_key` matches learner route keys: standalone public_id or `{modulePublicId}-core`.
            $table->string('lesson_key', 96);
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'lesson_key']);
            $table->index(['user_id', 'course_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_lesson_progress');
    }
};
