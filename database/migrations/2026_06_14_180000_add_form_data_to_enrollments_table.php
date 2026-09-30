<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('enrollments')) {
            return;
        }

        Schema::table('enrollments', function (Blueprint $table) {
            if (! Schema::hasColumn('enrollments', 'form_data')) {
                $table->json('form_data')->nullable();
            }
            if (! Schema::hasColumn('enrollments', 'documents')) {
                $table->json('documents')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('enrollments')) {
            return;
        }

        Schema::table('enrollments', function (Blueprint $table) {
            $drops = [];
            if (Schema::hasColumn('enrollments', 'documents')) {
                $drops[] = 'documents';
            }
            if (Schema::hasColumn('enrollments', 'form_data')) {
                $drops[] = 'form_data';
            }
            if ($drops !== []) {
                $table->dropColumn($drops);
            }
        });
    }
};
