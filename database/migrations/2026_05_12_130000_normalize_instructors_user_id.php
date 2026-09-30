<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Replace denormalized name/public_id with a proper FK to users.
     */
    public function up(): void
    {
        Schema::dropIfExists('instructors');

        Schema::create('instructors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->longText('achievements')->nullable();
            $table->string('profile_path', 2048)->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('instructors');

        Schema::create('instructors', function (Blueprint $table) {
            $table->id();
            $table->string('public_id', 64)->unique();
            $table->string('name');
            $table->string('status', 16)->default('active');
            $table->longText('achievements')->nullable();
            $table->string('profile_path', 2048)->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }
};
