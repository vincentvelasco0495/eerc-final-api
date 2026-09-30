<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('announcements', function (Blueprint $table) {
            $table->id();
            $table->string('public_id', 64)->unique();
            $table->foreignId('author_id')->constrained('users')->cascadeOnDelete();
            $table->string('title', 500);
            $table->longText('body')->nullable();
            $table->timestamps();
        });

        Schema::create('in_app_notifications', function (Blueprint $table) {
            $table->id();
            $table->string('public_id', 64)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('announcement_id')->constrained('announcements')->cascadeOnDelete();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'read_at', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('in_app_notifications');
        Schema::dropIfExists('announcements');
    }
};
