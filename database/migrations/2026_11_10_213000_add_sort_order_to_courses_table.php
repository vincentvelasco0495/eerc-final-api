<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            if (! Schema::hasColumn('courses', 'sort_order')) {
                $table->unsignedInteger('sort_order')->default(0)->after('is_published');
            }
        });

        $programIds = DB::table('courses')->distinct()->pluck('program_id');

        foreach ($programIds as $programId) {
            $ids = DB::table('courses')
                ->where('program_id', $programId)
                ->whereNull('deleted_at')
                ->orderByDesc('updated_at')
                ->orderBy('title')
                ->orderBy('id')
                ->pluck('id');

            $position = 1;
            foreach ($ids as $id) {
                DB::table('courses')->where('id', $id)->update(['sort_order' => $position]);
                $position++;
            }
        }
    }

    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            if (Schema::hasColumn('courses', 'sort_order')) {
                $table->dropColumn('sort_order');
            }
        });
    }
};
