<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leaderboard_entries', function (Blueprint $table) {
            $table->id();
            $table->string('period', 16)->index();
            $table->unsignedSmallInteger('rank_order');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('display_name')->nullable();
            $table->string('program_code', 16)->nullable();
            $table->unsignedInteger('score')->default(0);
            $table->string('badge_key', 64)->nullable();
            $table->timestamps();

            $table->index(['period', 'rank_order']);
        });

        Schema::create('admin_uploads', function (Blueprint $table) {
            $table->id();
            $table->string('public_id', 64)->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->string('asset_type', 64)->nullable();
            $table->string('status', 64)->default('Queued');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_uploads');
        Schema::dropIfExists('leaderboard_entries');
    }
};
