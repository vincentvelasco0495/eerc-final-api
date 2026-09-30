<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('assignment_attempts')) {
            return;
        }

        Schema::table('assignment_attempts', function (Blueprint $table) {
            if (! Schema::hasColumn('assignment_attempts', 'duration_used_seconds')) {
                $table->unsignedInteger('duration_used_seconds')->default(0)->after('duration_used_label');
            }
        });

        if (! Schema::hasColumn('assignment_attempts', 'duration_used_seconds')) {
            return;
        }

        DB::table('assignment_attempts')
            ->select(['id', 'duration_used_label'])
            ->orderBy('id')
            ->chunkById(200, function ($rows) {
                foreach ($rows as $row) {
                    DB::table('assignment_attempts')
                        ->where('id', $row->id)
                        ->update([
                            'duration_used_seconds' => $this->parseDurationLabelToSeconds($row->duration_used_label),
                        ]);
                }
            });
    }

    public function down(): void
    {
        if (! Schema::hasTable('assignment_attempts')) {
            return;
        }

        Schema::table('assignment_attempts', function (Blueprint $table) {
            if (Schema::hasColumn('assignment_attempts', 'duration_used_seconds')) {
                $table->dropColumn('duration_used_seconds');
            }
        });
    }

    protected function parseDurationLabelToSeconds(mixed $label): int
    {
        if (! is_string($label) || trim($label) === '') {
            return 0;
        }

        if (preg_match('/(\d+)m\s*(\d+)s/i', $label, $matches) === 1) {
            return max(0, ((int) $matches[1] * 60) + (int) $matches[2]);
        }

        return 0;
    }
};
