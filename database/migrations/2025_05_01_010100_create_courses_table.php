<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courses', function (Blueprint $table) {
            $table->id();
            $table->string('public_id', 64)->unique();
            $table->foreignId('program_id')->constrained('programs')->cascadeOnUpdate()->restrictOnDelete();
            $table->string('slug')->unique();
            $table->string('title');
            $table->string('mentor_display_name')->nullable();
            $table->text('description')->nullable();
            $table->string('level', 64)->nullable();
            $table->unsignedSmallInteger('total_modules')->default(0);
            $table->unsignedSmallInteger('hours')->default(0);
            $table->unsignedInteger('learners_count')->default(0);
            /** Logical “up next” module; no DB FK (optional + set after modules seeded). */
            $table->unsignedBigInteger('next_module_id')->nullable()->index();
            $table->string('video_hours_label')->nullable();
            $table->boolean('preview_completed')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('courses');
    }
};
