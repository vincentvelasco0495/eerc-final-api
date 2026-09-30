<?php

use App\Models\Module;
use App\Models\ModuleResource;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('modules', function (Blueprint $table) {
            $table->text('excerpt_html')->nullable()->after('summary');
            $table->longText('body_html')->nullable()->after('excerpt_html');
            $table->json('lesson_meta_json')->nullable()->after('body_html');
        });

        Schema::table('module_resources', function (Blueprint $table) {
            $table->text('excerpt_html')->nullable()->after('summary');
            $table->longText('body_html')->nullable()->after('excerpt_html');
            $table->json('lesson_meta_json')->nullable()->after('body_html');
        });

        Schema::create('lesson_materials', function (Blueprint $table) {
            $table->id();
            $table->string('public_id', 64)->unique();
            /** Core module attachment: set, standalone row null */
            $table->foreignId('module_id')->nullable()->constrained('modules')->cascadeOnDelete();
            /** Standalone curriculum row attachment: module_id null */
            $table->foreignId('module_resource_id')->nullable()->constrained('module_resources')->cascadeOnDelete();
            $table->string('original_name', 512);
            $table->string('storage_path', 2048);
            $table->string('mime', 128)->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->timestamps();

            $table->index(['module_id']);
            $table->index(['module_resource_id']);
        });

        Module::query()
            ->whereNotNull('summary')
            ->whereNull('body_html')
            ->orderBy('id')
            ->each(function (Module $m) {
                $m->body_html = (string) $m->summary;
                $m->save();
            });

        ModuleResource::query()
            ->where('is_standalone_lesson', true)
            ->whereNotNull('summary')
            ->whereNull('body_html')
            ->orderBy('id')
            ->each(function (ModuleResource $r) {
                $r->body_html = (string) $r->summary;
                $r->save();
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_materials');

        Schema::table('module_resources', function (Blueprint $table) {
            $table->dropColumn(['excerpt_html', 'body_html', 'lesson_meta_json']);
        });

        Schema::table('modules', function (Blueprint $table) {
            $table->dropColumn(['excerpt_html', 'body_html', 'lesson_meta_json']);
        });
    }
};
