<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_module_progress', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('module_id')->constrained('modules')->cascadeOnUpdate()->cascadeOnDelete();
            $table->unsignedTinyInteger('progress_percent')->default(0);
            $table->string('last_position_label')->nullable();
            $table->timestamps();

            $table->primary(['user_id', 'module_id']);
        });

        Schema::create('quiz_attempts', function (Blueprint $table) {
            $table->id();
            $table->string('public_id', 64)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('quiz_id')->constrained('quizzes')->cascadeOnUpdate()->cascadeOnDelete();
            $table->date('attempted_on');
            $table->unsignedSmallInteger('score')->default(0);
            $table->string('duration_used_label')->nullable();
            $table->unsignedSmallInteger('correct_answers')->default(0);
            $table->unsignedSmallInteger('total_questions')->default(0);
            $table->timestamps();

            $table->index(['user_id', 'quiz_id']);
        });

        Schema::create('enrollments', function (Blueprint $table) {
            $table->id();
            $table->string('public_id', 64)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_id')->constrained('courses')->cascadeOnUpdate()->cascadeOnDelete();
            $table->string('status', 32);
            $table->date('submitted_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'course_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enrollments');
        Schema::dropIfExists('quiz_attempts');
        Schema::dropIfExists('user_module_progress');
    }
};
