<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Account-level status lives on users; instructor rows keep only profile fields.
     * Students roster mirrors instructors (FK to users).
     */
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'status')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('status', 16)->default('active')->after('role');
            });
        }

        if (Schema::hasTable('instructors') && Schema::hasColumn('instructors', 'status')) {
            $driver = Schema::getConnection()->getDriverName();
            if ($driver === 'sqlite') {
                DB::statement('
                    UPDATE users
                    SET status = (
                        SELECT instructors.status FROM instructors WHERE instructors.user_id = users.id LIMIT 1
                    )
                    WHERE EXISTS (SELECT 1 FROM instructors WHERE instructors.user_id = users.id)
                ');
            } else {
                DB::statement('
                    UPDATE users
                    INNER JOIN instructors ON users.id = instructors.user_id
                    SET users.status = instructors.status
                ');
            }

            Schema::table('instructors', function (Blueprint $table) {
                $table->dropColumn('status');
            });
        }

        if (! Schema::hasTable('students')) {
            Schema::create('students', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
                $table->longText('notes')->nullable();
                $table->string('profile_path', 2048)->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('students');

        if (Schema::hasTable('instructors') && ! Schema::hasColumn('instructors', 'status')) {
            Schema::table('instructors', function (Blueprint $table) {
                $table->string('status', 16)->default('active')->after('user_id');
            });

            $driver = Schema::getConnection()->getDriverName();
            if ($driver === 'sqlite') {
                DB::statement('
                    UPDATE instructors
                    SET status = (
                        SELECT users.status FROM users WHERE users.id = instructors.user_id LIMIT 1
                    )
                    WHERE EXISTS (SELECT 1 FROM users WHERE users.id = instructors.user_id)
                ');
            } else {
                DB::statement('
                    UPDATE instructors
                    INNER JOIN users ON users.id = instructors.user_id
                    SET instructors.status = users.status
                ');
            }
        }

        if (Schema::hasColumn('users', 'status')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('status');
            });
        }
    }
};
