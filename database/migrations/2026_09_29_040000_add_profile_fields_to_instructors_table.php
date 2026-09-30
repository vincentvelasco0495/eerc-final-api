<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instructors', function (Blueprint $table) {
            if (! Schema::hasColumn('instructors', 'display_name')) {
                $table->string('display_name', 120)->nullable()->after('achievements');
            }
            if (! Schema::hasColumn('instructors', 'position')) {
                $table->string('position', 255)->nullable()->after('display_name');
            }
            if (! Schema::hasColumn('instructors', 'bio')) {
                $table->text('bio')->nullable()->after('position');
            }
            if (! Schema::hasColumn('instructors', 'facebook')) {
                $table->string('facebook', 2048)->nullable()->after('bio');
            }
            if (! Schema::hasColumn('instructors', 'linkedin')) {
                $table->string('linkedin', 2048)->nullable()->after('facebook');
            }
            if (! Schema::hasColumn('instructors', 'twitter')) {
                $table->string('twitter', 2048)->nullable()->after('linkedin');
            }
            if (! Schema::hasColumn('instructors', 'instagram')) {
                $table->string('instagram', 2048)->nullable()->after('twitter');
            }
            if (! Schema::hasColumn('instructors', 'cover_path')) {
                $table->string('cover_path', 2048)->nullable()->after('profile_path');
            }
        });
    }

    public function down(): void
    {
        Schema::table('instructors', function (Blueprint $table) {
            $columns = [
                'display_name',
                'position',
                'bio',
                'facebook',
                'linkedin',
                'twitter',
                'instagram',
                'cover_path',
            ];
            $existing = array_values(array_filter(
                $columns,
                static fn (string $column): bool => Schema::hasColumn('instructors', $column)
            ));
            if ($existing !== []) {
                $table->dropColumn($existing);
            }
        });
    }
};
