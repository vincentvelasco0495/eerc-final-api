<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cms_media', function (Blueprint $table) {
            $table->id();
            $table->string('public_id', 64)->unique();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('disk', 32)->default('public');
            $table->string('path');
            $table->string('url');
            $table->string('filename');
            $table->string('original_name')->nullable();
            $table->string('mime', 128)->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->string('alt')->nullable();
            $table->timestamps();
        });

        Schema::create('homepage_sections', function (Blueprint $table) {
            $table->id();
            $table->string('section_key', 64)->unique();
            $table->string('title');
            $table->json('content_json');
            $table->string('status', 16)->default('published');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('homepage_sections');
        Schema::dropIfExists('cms_media');
    }
};
