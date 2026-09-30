<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quizzes', function (Blueprint $table) {
            $table->id();
            $table->string('public_id', 64)->unique();
            $table->foreignId('course_id')->constrained('courses')->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreignId('module_id')->nullable()->constrained('modules')->nullOnDelete()->cascadeOnUpdate();
            $table->string('title');
            $table->unsignedSmallInteger('duration_minutes')->default(0);
            $table->unsignedTinyInteger('attempts_allowed')->default(1);
            $table->unsignedSmallInteger('question_count')->default(0);
            $table->unsignedSmallInteger('question_pool_count')->default(0);
            $table->timestamps();
        });

        Schema::create('questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quiz_id')->constrained('quizzes')->cascadeOnUpdate()->cascadeOnDelete();
            $table->text('prompt');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['quiz_id', 'sort_order']);
        });

        Schema::create('question_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('question_id')->constrained('questions')->cascadeOnUpdate()->cascadeOnDelete();
            $table->string('label');
            $table->boolean('is_correct')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('question_options');
        Schema::dropIfExists('questions');
        Schema::dropIfExists('quizzes');
    }
};
