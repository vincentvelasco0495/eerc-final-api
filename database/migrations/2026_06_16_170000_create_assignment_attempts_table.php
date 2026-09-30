<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('assignment_attempts')) {
            return;
        }

        Schema::create('assignment_attempts', function (Blueprint $table) {
            $table->id();
            $table->string('public_id', 64)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assignment_id')->constrained('assignments')->cascadeOnUpdate()->cascadeOnDelete();
            $table->date('attempted_on');
            $table->unsignedSmallInteger('score')->default(0);
            $table->string('duration_used_label')->nullable();
            $table->unsignedSmallInteger('correct_answers')->default(0);
            $table->unsignedSmallInteger('total_questions')->default(0);
            $table->timestamps();

            $table->index(['user_id', 'assignment_id'], 'asg_attempts_user_asg_idx');
            $table->index(['assignment_id', 'user_id'], 'asg_attempts_asg_user_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assignment_attempts');
    }
};
