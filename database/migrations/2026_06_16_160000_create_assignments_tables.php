<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('assignments')) {
            Schema::create('assignments', function (Blueprint $table) {
                $table->id();
                $table->string('public_id', 64)->unique();
                $table->foreignId('course_id')->constrained()->cascadeOnDelete();
                $table->foreignId('module_id')->nullable()->constrained()->nullOnDelete();
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->string('title');
                $table->longText('content_html')->nullable();
                $table->unsignedSmallInteger('duration_minutes')->default(0);
                $table->unsignedTinyInteger('attempts_allowed')->default(1);
                $table->json('settings_json')->nullable();
                $table->unsignedSmallInteger('question_count')->default(0);
                $table->timestamps();

                $table->index(['module_id', 'sort_order'], 'assignments_module_order_idx');
            });
        }

        if (! Schema::hasTable('assignment_questions')) {
            Schema::create('assignment_questions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('assignment_id')->constrained()->cascadeOnDelete();
                $table->text('prompt');
                $table->string('question_type', 32)->default('single_choice');
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->timestamps();

                $table->index(['assignment_id', 'sort_order'], 'assignment_questions_order_idx');
            });
        }

        if (! Schema::hasTable('assignment_question_options')) {
            Schema::create('assignment_question_options', function (Blueprint $table) {
                $table->id();
                $table->foreignId('assignment_question_id')->constrained()->cascadeOnDelete();
                $table->string('label');
                $table->boolean('is_correct')->default(false);
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->timestamps();

                $table->index(['assignment_question_id', 'sort_order'], 'assignment_options_order_idx');
            });
        }

        if (Schema::hasTable('lesson_materials') && ! Schema::hasColumn('lesson_materials', 'assignment_id')) {
            Schema::table('lesson_materials', function (Blueprint $table) {
                $table->foreignId('assignment_id')->nullable()->after('module_resource_id')->constrained()->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('lesson_materials') && Schema::hasColumn('lesson_materials', 'assignment_id')) {
            Schema::table('lesson_materials', function (Blueprint $table) {
                $table->dropConstrainedForeignId('assignment_id');
            });
        }

        Schema::dropIfExists('assignment_question_options');
        Schema::dropIfExists('assignment_questions');
        Schema::dropIfExists('assignments');
    }
};
