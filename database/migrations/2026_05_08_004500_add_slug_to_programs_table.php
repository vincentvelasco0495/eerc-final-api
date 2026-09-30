<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('programs', function (Blueprint $table) {
            $table->string('slug', 191)->nullable()->after('code');
        });

        $usedSlugs = [];
        $programs = DB::table('programs')->orderBy('id')->get(['id', 'code', 'title']);

        foreach ($programs as $program) {
            $baseSlug = Str::slug((string) ($program->title ?: $program->code));
            if ($baseSlug === '') {
                $baseSlug = 'program';
            }

            $slug = $baseSlug;
            $suffix = 2;
            while (in_array($slug, $usedSlugs, true)) {
                $slug = $baseSlug.'-'.$suffix;
                $suffix++;
            }

            DB::table('programs')->where('id', $program->id)->update(['slug' => $slug]);
            $usedSlugs[] = $slug;
        }

        Schema::table('programs', function (Blueprint $table) {
            $table->unique('slug');
        });
    }

    public function down(): void
    {
        Schema::table('programs', function (Blueprint $table) {
            $table->dropUnique(['slug']);
            $table->dropColumn('slug');
        });
    }
};
