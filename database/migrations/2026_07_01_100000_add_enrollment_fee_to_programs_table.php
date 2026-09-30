<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('programs')) {
            return;
        }

        Schema::table('programs', function (Blueprint $table) {
            if (! Schema::hasColumn('programs', 'enrollment_fee')) {
                $table->decimal('enrollment_fee', 12, 2)->nullable()->after('description');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('programs')) {
            return;
        }

        Schema::table('programs', function (Blueprint $table) {
            if (Schema::hasColumn('programs', 'enrollment_fee')) {
                $table->dropColumn('enrollment_fee');
            }
        });
    }
};
