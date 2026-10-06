<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('students')) {
            return;
        }

        if (! Schema::hasColumn('students', 'alias_name')) {
            Schema::table('students', function (Blueprint $table) {
                $table->string('alias_name', 255)->nullable()->after('school_held');
            });
        }

        if (! Schema::hasTable('enrollments') || ! Schema::hasColumn('enrollments', 'form_data')) {
            return;
        }

        $rows = DB::table('enrollments')
            ->whereNotNull('form_data')
            ->orderByDesc('id')
            ->get(['user_id', 'form_data']);

        foreach ($rows as $row) {
            $form = is_array($row->form_data)
                ? $row->form_data
                : json_decode((string) $row->form_data, true);
            if (! is_array($form)) {
                continue;
            }

            $alias = trim((string) ($form['aliasName'] ?? $form['alias_name'] ?? ''));
            if ($alias === '') {
                continue;
            }

            DB::table('students')
                ->where('user_id', $row->user_id)
                ->where(function ($query) {
                    $query->whereNull('alias_name')->orWhere('alias_name', '');
                })
                ->update(['alias_name' => $alias]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('students') && Schema::hasColumn('students', 'alias_name')) {
            Schema::table('students', function (Blueprint $table) {
                $table->dropColumn('alias_name');
            });
        }
    }
};
