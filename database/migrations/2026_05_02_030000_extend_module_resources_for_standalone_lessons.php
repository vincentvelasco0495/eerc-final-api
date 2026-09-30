<?php

use App\Models\ModuleResource;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('module_resources', function (Blueprint $table) {
            $table->string('public_id', 64)->nullable()->unique()->after('id');
            $table->string('title')->nullable()->after('module_id');
            $table->string('lesson_kind', 24)->nullable()->after('title');
            $table->boolean('is_standalone_lesson')->default(false)->after('lesson_kind');
            $table->text('summary')->nullable()->after('is_standalone_lesson');
        });

        ModuleResource::query()->whereNull('public_id')->orderBy('id')->each(function (ModuleResource $row) {
            $row->public_id = (string) Str::uuid();
            $row->save();
        });
    }

    public function down(): void
    {
        Schema::table('module_resources', function (Blueprint $table) {
            $table->dropColumn(['public_id', 'title', 'lesson_kind', 'is_standalone_lesson', 'summary']);
        });
    }
};
