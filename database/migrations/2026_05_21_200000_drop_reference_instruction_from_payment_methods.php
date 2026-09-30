<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_payment_methods', function (Blueprint $table) {
            $table->dropColumn('reference_instruction');
        });

        Schema::table('ewallet_payment_methods', function (Blueprint $table) {
            $table->dropColumn('reference_instruction');
        });
    }

    public function down(): void
    {
        Schema::table('bank_payment_methods', function (Blueprint $table) {
            $table->text('reference_instruction')->nullable()->after('account_number');
        });

        Schema::table('ewallet_payment_methods', function (Blueprint $table) {
            $table->text('reference_instruction')->nullable()->after('account_name');
        });
    }
};
