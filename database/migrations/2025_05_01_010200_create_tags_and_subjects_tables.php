<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });

        Schema::create('course_tag', function (Blueprint $table) {
            $table->foreignId('course_id')->constrained('courses')->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained('tags')->cascadeOnDelete();
            $table->primary(['course_id', 'tag_id']);
        });

        Schema::create('subjects', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });

        Schema::create('course_subject', function (Blueprint $table) {
            $table->foreignId('course_id')->constrained('courses')->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained('subjects')->cascadeOnDelete();
            $table->primary(['course_id', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('course_subject');
        Schema::dropIfExists('subjects');
        Schema::dropIfExists('course_tag');
        Schema::dropIfExists('tags');
    }
};
