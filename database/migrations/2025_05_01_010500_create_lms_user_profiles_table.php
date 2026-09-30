<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lms_user_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('program_id')->nullable()->constrained('programs')->nullOnDelete()->cascadeOnUpdate();
            $table->unsignedSmallInteger('streak_days')->default(0);
            $table->string('watermark_name')->nullable();
            $table->boolean('session_warning')->default(false);
            $table->date('joined_at')->nullable();
            $table->timestamps();

            $table->unique('user_id');
        });

        Schema::create('user_badges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('badge_key', 64);
            $table->string('badge_label')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'badge_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_badges');
        Schema::dropIfExists('lms_user_profiles');
    }
};
