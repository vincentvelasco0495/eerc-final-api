<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('role_page_permissions', function (Blueprint $table) {
            $table->id();
            $table->string('role', 32);
            $table->string('path', 255);
            $table->string('match_type', 16)->default('exact');
            $table->json('query')->nullable();
            $table->string('label')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['role', 'path']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_page_permissions');
    }
};
