<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_payment_methods', function (Blueprint $table) {
            $table->id();
            $table->string('public_id', 64)->unique();
            $table->string('account_name');
            $table->string('bank_name');
            $table->string('account_number', 191);
            $table->text('reference_instruction')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('ewallet_payment_methods', function (Blueprint $table) {
            $table->id();
            $table->string('public_id', 64)->unique();
            $table->string('mobile_number', 64);
            $table->string('account_name');
            $table->text('reference_instruction')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ewallet_payment_methods');
        Schema::dropIfExists('bank_payment_methods');
    }
};
