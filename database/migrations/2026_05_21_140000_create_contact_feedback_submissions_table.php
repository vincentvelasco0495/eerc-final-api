<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_feedback_submissions', function (Blueprint $table) {
            $table->id();
            $table->string('name', 190);
            $table->string('email', 190);
            $table->string('phone', 40)->nullable();
            $table->text('message');
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 1024)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_feedback_submissions');
    }
};
