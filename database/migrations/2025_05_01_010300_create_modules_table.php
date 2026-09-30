<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('modules', function (Blueprint $table) {
            $table->id();
            $table->string('public_id', 64)->unique();
            $table->foreignId('course_id')->constrained('courses')->cascadeOnUpdate()->cascadeOnDelete();
            $table->string('title');
            $table->string('subject')->nullable();
            $table->string('topic')->nullable();
            $table->string('subtopic')->nullable();
            $table->string('learning_flow_step', 64)->nullable();
            $table->string('duration_label')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_visible')->default(true);
            $table->boolean('streaming_only')->default(false);
            $table->text('summary')->nullable();
            $table->timestamps();

            $table->index(['course_id', 'sort_order']);
        });

        Schema::create('module_resources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('module_id')->constrained('modules')->cascadeOnUpdate()->cascadeOnDelete();
            $table->string('format', 32);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['module_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('module_resources');
        Schema::dropIfExists('modules');
    }
};
