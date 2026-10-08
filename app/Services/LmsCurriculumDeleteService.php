<?php

namespace App\Services;

use App\Models\Assignment;
use App\Models\Course;
use App\Models\LessonMaterial;
use App\Models\Module;
use App\Models\ModuleResource;
use App\Models\Quiz;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class LmsCurriculumDeleteService
{
    public function softDeleteStandaloneLesson(ModuleResource $row): void
    {
        DB::transaction(function () use ($row) {
            $this->purgeVideoMaterials(
                LessonMaterial::query()->where('module_resource_id', $row->id)->get()
            );
            $row->delete();
        });
    }

    public function softDeleteModule(Module $module): void
    {
        DB::transaction(function () use ($module) {
            Course::query()->where('next_module_id', $module->id)->update(['next_module_id' => null]);

            $resourceIds = ModuleResource::query()->where('module_id', $module->id)->pluck('id');
            $assignmentIds = Assignment::query()->where('module_id', $module->id)->pluck('id');

            $materials = LessonMaterial::query()
                ->where(function ($query) use ($module, $resourceIds, $assignmentIds) {
                    $query->where('module_id', $module->id);
                    if ($resourceIds->isNotEmpty()) {
                        $query->orWhereIn('module_resource_id', $resourceIds);
                    }
                    if ($assignmentIds->isNotEmpty()) {
                        $query->orWhereIn('assignment_id', $assignmentIds);
                    }
                })
                ->get();

            $this->purgeVideoMaterials($materials);

            Quiz::query()->where('module_id', $module->id)->delete();
            Assignment::query()->where('module_id', $module->id)->delete();
            ModuleResource::query()->where('module_id', $module->id)->delete();
            $module->delete();
        });
    }

    public function softDeleteQuiz(Quiz $quiz): void
    {
        $quiz->delete();
    }

    /** Soft-delete a course and its curriculum rows so they disappear from every list. */
    public function softDeleteCourse(Course $course): void
    {
        DB::transaction(function () use ($course) {
            $modules = Module::query()->where('course_id', $course->id)->get();
            foreach ($modules as $module) {
                Quiz::query()->where('module_id', $module->id)->delete();
                Assignment::query()->where('module_id', $module->id)->delete();
                ModuleResource::query()->where('module_id', $module->id)->delete();
                $module->delete();
            }

            Quiz::query()->where('course_id', $course->id)->delete();
            Assignment::query()->where('course_id', $course->id)->delete();
            $course->delete();
        });
    }

    public function softDeleteAssignment(Assignment $assignment): void
    {
        DB::transaction(function () use ($assignment) {
            $this->purgeVideoMaterials(
                LessonMaterial::query()->where('assignment_id', $assignment->id)->get()
            );
            $assignment->delete();
        });
    }

    /**
     * Remove video files from disk, then drop the material rows (file is gone).
     *
     * @param  Collection<int, LessonMaterial>|iterable<LessonMaterial>  $materials
     */
    public function purgeVideoMaterials(iterable $materials): void
    {
        foreach ($materials as $material) {
            if (! $material instanceof LessonMaterial || ! $this->isVideoMaterial($material)) {
                continue;
            }

            $this->deleteStoredFile($material);
            $material->delete();
        }
    }

    public function isVideoMaterial(LessonMaterial $material): bool
    {
        $mime = strtolower((string) ($material->mime ?? ''));
        if (str_starts_with($mime, 'video/')) {
            return true;
        }

        $path = str_replace('\\', '/', (string) ($material->storage_path ?? ''));

        return $path !== '' && str_contains($path, 'lesson-materials/videos/');
    }

    protected function deleteStoredFile(LessonMaterial $material): void
    {
        $path = trim((string) ($material->storage_path ?? ''));
        if ($path === '') {
            return;
        }

        foreach (['local', 'public'] as $disk) {
            try {
                if (Storage::disk($disk)->exists($path)) {
                    Storage::disk($disk)->delete($path);
                }
            } catch (\Throwable) {
                // Continue other disks even if one unlink fails.
            }
        }
    }
}
