<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\Concerns\ResolvesLmsActor;
use App\Http\Controllers\Controller;
use App\Models\Assignment;
use App\Models\LessonMaterial;
use App\Models\Module;
use App\Models\ModuleResource;
use App\Services\LmsCatalogService;
use App\Support\LessonPlaybackToken;
use App\Support\MediaHotlinkGuard;
use App\Support\RangedFileResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class LmsLessonMaterialController extends Controller
{
    use ResolvesLmsActor;

    public function storeForModule(Request $request, string $modulePublicId): JsonResponse
    {
        $this->lmsActor();

        /** @var Module $module */
        $module = Module::query()->where('public_id', $modulePublicId)->firstOrFail();

        $request->validate([
            'moduleResourcePublicId' => ['sometimes', 'nullable', 'string', 'max:64'],
        ]);

        $resourcePublicId = $request->input('moduleResourcePublicId');
        if (! is_string($resourcePublicId) || trim($resourcePublicId) === '') {
            return $this->persistUpload($request, $module->id, null);
        }

        $resource = ModuleResource::query()
            ->where('public_id', trim($resourcePublicId))
            ->where('module_id', $module->id)
            ->first();

        // Stale client id (e.g. another course) or core lesson with no `module_resources` row yet:
        // attach to the module only (same as omitting the field).
        if ($resource === null) {
            return $this->persistUpload($request, $module->id, null);
        }

        if ($resource->is_standalone_lesson) {
            return $this->persistUpload($request, null, $resource->id);
        }

        return $this->persistUpload($request, $module->id, $resource->id);
    }

    public function storeForStandaloneLesson(Request $request, string $publicId): JsonResponse
    {
        $this->lmsActor();

        /** @var ModuleResource $resource */
        $resource = ModuleResource::query()
            ->where('public_id', $publicId)
            ->where('is_standalone_lesson', true)
            ->firstOrFail();

        return $this->persistUpload($request, null, $resource->id);
    }

    public function storeForAssignment(Request $request, string $assignmentPublicId): JsonResponse
    {
        $this->lmsActor();

        /** @var Assignment $assignment */
        $assignment = Assignment::query()->where('public_id', $assignmentPublicId)->firstOrFail();

        return $this->persistUpload($request, null, null, $assignment->id);
    }

    public function destroy(string $publicId): JsonResponse
    {
        $actor = $this->lmsActor();

        $row = LessonMaterial::query()->where('public_id', $publicId)->first();

        if ($row === null) {
            /** Idempotent: stale client meta / prior cleanup — do not 404. */
            return response()->json(['ok' => true]);
        }

        if (Storage::disk('public')->exists($row->storage_path)) {
            Storage::disk('public')->delete($row->storage_path);
        }
        if (Storage::disk('local')->exists($row->storage_path)) {
            Storage::disk('local')->delete($row->storage_path);
        }

        $row->delete();

        LmsCatalogService::bustUserAnalyticsCache($actor->id);

        return response()->json(['ok' => true]);
    }

    /**
     * Issue a short-lived playback URL for the in-app player (authenticated).
     */
    public function playback(string $publicId): JsonResponse
    {
        $actor = $this->lmsActor();
        if ((int) $actor->id <= 0) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $row = LessonMaterial::query()->where('public_id', $publicId)->first();
        if ($row === null) {
            return response()->json(['message' => 'Lesson material not found.'], 404);
        }

        if ($denied = $this->videoPlaybackDeniedResponse($actor, $row)) {
            return $denied;
        }

        $ttl = (int) config('lms.video.playback_ttl', 28800);
        $token = LessonPlaybackToken::issue($publicId, $ttl);
        $path = '/api/lesson-materials/'.rawurlencode($publicId).'/file?inline=1&play='.rawurlencode($token);

        return response()->json([
            'data' => [
                'path' => $path,
                'expiresAt' => now()->addSeconds($ttl)->toIso8601String(),
            ],
        ]);
    }

    /**
     * Stream lesson material bytes.
     * Videos require a playback token and cannot be opened as a document URL.
     */
    public function download(Request $request, string $publicId): BinaryFileResponse|\Illuminate\Http\Response
    {
        $row = LessonMaterial::query()->where('public_id', $publicId)->first();

        if ($row === null) {
            return response()->json(['message' => 'Lesson material not found.'], 404);
        }

        $mime = is_string($row->mime) && trim($row->mime) !== ''
            ? trim((string) $row->mime)
            : 'application/octet-stream';
        $isVideo = str_starts_with(strtolower($mime), 'video/');

        if ($isVideo) {
            MediaHotlinkGuard::denyDirectOpen($request);
            LessonPlaybackToken::assertValid($request->query('play'), $publicId);
        }

        $disk = Storage::disk('local')->exists($row->storage_path)
            ? Storage::disk('local')
            : (Storage::disk('public')->exists($row->storage_path) ? Storage::disk('public') : null);

        if ($disk === null) {
            return response()->json(['message' => 'File missing on storage.'], 404);
        }

        $absolutePath = $disk->path($row->storage_path);
        $accelRel = $disk === Storage::disk('public') ? ltrim((string) $row->storage_path, '/') : null;

        if ($request->boolean('inline') || $isVideo) {
            return RangedFileResponse::make(
                $absolutePath,
                $mime,
                true,
                $row->original_name,
                $accelRel
            );
        }

        return RangedFileResponse::make(
            $absolutePath,
            $mime,
            false,
            $row->original_name,
            $accelRel
        );
    }

    protected function videoPlaybackDeniedResponse($actor, LessonMaterial $row): ?JsonResponse
    {
        if ($row->assignment_id !== null || $row->isQuizQuestionImage()) {
            return null;
        }

        $mime = is_string($row->mime) && trim($row->mime) !== ''
            ? trim((string) $row->mime)
            : '';
        if (! str_starts_with(strtolower($mime), 'video/')) {
            return null;
        }

        $row->loadMissing(['module.course', 'moduleResource.module.course']);
        $course = $row->module?->course ?? $row->moduleResource?->module?->course;
        if ($course === null) {
            return null;
        }

        $message = app(LmsCatalogService::class)->curriculumAccessDeniedMessage($actor, $course, 'video');
        if ($message === null) {
            return null;
        }

        return response()->json(['message' => $message], 403);
    }

    protected function persistUpload(Request $request, ?int $moduleId, ?int $moduleResourceId, ?int $assignmentId = null): JsonResponse
    {
        $actor = $this->lmsActor();

        if ($moduleId === null && $moduleResourceId === null && $assignmentId === null) {
            return response()->json(['message' => 'Invalid attachment target.'], 422);
        }

        $request->validate([
            'file' => ['required', 'file', 'max:40960'],
            'usage' => ['sometimes', 'nullable', 'string', 'in:lesson,quiz_question_image'],
        ]);

        $uploaded = $request->file('file');
        $usage = strtolower(trim((string) $request->input('usage', 'lesson')));
        if ($usage === '') {
            $usage = 'lesson';
        }

        $stored = Storage::disk('public')->putFile(
            match (true) {
                $usage === 'quiz_question_image' => 'lesson-materials/quiz-questions',
                $assignmentId !== null => 'lesson-materials/assignments',
                $moduleId !== null => 'lesson-materials/modules',
                default => 'lesson-materials/standalone',
            },
            $uploaded
        );

        $attrs = [
            'public_id' => (string) Str::uuid(),
            'module_id' => $moduleId,
            'module_resource_id' => $moduleResourceId,
            'assignment_id' => $assignmentId,
            'original_name' => $uploaded->getClientOriginalName() ?: ('file-'.$uploaded->hashName()),
            'storage_path' => $stored,
            'mime' => $uploaded->getMimeType(),
            'size_bytes' => (int) $uploaded->getSize(),
        ];
        if (Schema::hasColumn('lesson_materials', 'usage')) {
            $attrs['usage'] = $usage;
        }

        $material = LessonMaterial::query()->create($attrs);

        LmsCatalogService::bustUserAnalyticsCache($actor->id);

        $catalog = app(LmsCatalogService::class);

        return response()->json([
            'data' => $catalog->formatLessonMaterial($material->fresh(), $actor),
        ], 201);
    }
}
