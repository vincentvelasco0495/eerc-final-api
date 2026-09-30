<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->string('phone_number', 32)->nullable()->after('profile_path');
            $table->date('birthday')->nullable()->after('phone_number');
            $table->string('school_held', 255)->nullable()->after('birthday');
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropColumn(['phone_number', 'birthday', 'school_held']);
        });
    }
};
