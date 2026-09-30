<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('package_enrolls')) {
            return;
        }

        Schema::create('package_enrolls', function (Blueprint $table) {
            $table->id();
            $table->string('public_id', 64)->unique();
            $table->string('name', 512);
            $table->text('description')->nullable();
            $table->string('status', 16)->default('active');
            $table->unsignedSmallInteger('sort_order')->default(1);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('package_enrolls');
    }
};
