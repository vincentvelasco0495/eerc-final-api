<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\Concerns\ResolvesLmsActor;
use App\Http\Controllers\Controller;
use App\Models\Assignment;
use App\Models\Course;
use App\Models\Module;
use App\Models\ModuleResource;
use App\Models\Quiz;
use App\Services\LmsCatalogService;
use App\Support\LessonMetaSupport;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class LmsModuleController extends Controller
{
    use ResolvesLmsActor;

    public function index(Request $request, LmsCatalogService $catalog): JsonResponse
    {
        $courseId = $request->query('courseId');
        $idsRaw = $request->query('ids');

        $user = $this->lmsActor();

        if (is_string($idsRaw) && trim($idsRaw) !== '') {
            $ids = array_values(array_filter(array_map('trim', explode(',', $idsRaw))));

            return response()->json(['data' => $catalog->modulesByPublicIds($user, $ids)]);
        }

        if (! is_string($courseId) || trim($courseId) === '') {
            return response()->json(['message' => 'courseId or ids query parameter is required.'], 422);
        }

        return response()->json(['data' => $catalog->modulesForCourse($user, $courseId)]);
    }

    /** Create an empty curriculum module appended to the course (instructor authoring). */
    public function store(Request $request, string $coursePublicId, LmsCatalogService $catalog): JsonResponse
    {
        $actor = $this->lmsActor();

        $course = Course::query()->where('public_id', $coursePublicId)->firstOrFail();

        $validated = $request->validate([
            'title' => ['sometimes', 'nullable', 'string', 'max:512'],
            'streaming_only' => ['sometimes', 'boolean'],
        ]);

        $maxOrder = (int) Module::query()->where('course_id', $course->id)->max('sort_order');

        $title = isset($validated['title']) ? trim((string) $validated['title']) : '';
        if ($title === '') {
            $title = 'Untitled module';
        }

        $streamingOnly = array_key_exists('streaming_only', $validated)
            ? (bool) $validated['streaming_only']
            : false;

        $module = Module::query()->create([
            'public_id' => (string) Str::uuid(),
            'course_id' => $course->id,
            'title' => $title,
            'sort_order' => $maxOrder + 1,
            'is_visible' => true,
            'streaming_only' => $streamingOnly,
            'subject' => null,
            'topic' => null,
            'summary' => null,
        ]);

        LmsCatalogService::bustUserAnalyticsCache($actor->id);

        return response()->json([
            'data' => $catalog->modulePayloadForUser(
                $module->fresh([
                    'resources.lessonMaterials.moduleResource',
                    'moduleLessonMaterials.moduleResource',
                    'quizzes' => fn ($query) => $query->withCount('questions'),
                    'course',
                ]),
                $actor
            ),
        ], 201);
    }

    /** Add a curriculum lesson row scoped to one module (`module_resources` + `standaloneLessons`). */
    public function storeStandaloneLesson(Request $request, string $modulePublicId): JsonResponse
    {
        $actor = $this->lmsActor();

        /** @var Module $module */
        $module = Module::query()->where('public_id', $modulePublicId)->firstOrFail();

        $validated = $request->validate([
            'title' => ['sometimes', 'nullable', 'string', 'max:512'],
            'lesson_kind' => ['required', 'string', 'in:document,video,stream,zoom'],
        ]);

        $title = isset($validated['title']) ? trim((string) $validated['title']) : '';
        $kind = (string) $validated['lesson_kind'];

        $format = match ($kind) {
            'document' => 'Text',
            'video' => 'Video',
            'stream' => 'Stream',
            'zoom' => 'Zoom',
            default => 'Text',
        };

        if ($title === '') {
            $title = match ($kind) {
                'document' => 'Text lesson',
                'video' => 'Video lesson',
                'stream' => 'Stream lesson',
                'zoom' => 'Zoom lesson',
                default => 'Lesson',
            };
        }

        $maxSort = (int) ModuleResource::query()->where('module_id', $module->id)->max('sort_order');

        $resource = ModuleResource::query()->create([
            'public_id' => (string) Str::uuid(),
            'module_id' => $module->id,
            'title' => $title,
            'lesson_kind' => $kind,
            'format' => $format,
            'is_standalone_lesson' => true,
            'sort_order' => $maxSort + 1,
            'summary' => null,
        ]);

        LmsCatalogService::bustUserAnalyticsCache($actor->id);

        return response()->json([
            'data' => [
                'id' => $resource->public_id,
                'moduleId' => $module->public_id,
                'title' => $resource->title,
                'kind' => $resource->lesson_kind,
            ],
        ], 201);
    }

    public function updateStandaloneLesson(
        Request $request,
        string $publicId,
        LmsCatalogService $catalog
    ): JsonResponse {
        $actor = $this->lmsActor();

        /** @var ModuleResource $row */
        $row = ModuleResource::query()
            ->where('public_id', $publicId)
            ->where('is_standalone_lesson', true)
            ->firstOrFail();

        $validated = $request->validate([
            'title' => ['sometimes', 'string', 'max:512'],
            'summary' => ['sometimes', 'nullable', 'string'],
            'excerpt_html' => ['sometimes', 'nullable', 'string', 'max:65000'],
            'body_html' => ['sometimes', 'nullable', 'string', 'max:650000'],
            'lesson_meta' => ['sometimes', 'nullable', 'array'],
        ]);

        if (array_key_exists('title', $validated)) {
            $trim = trim((string) $validated['title']);
            $row->title = $trim === '' ? 'Lesson' : $trim;
        }

        if (array_key_exists('excerpt_html', $validated)) {
            $raw = $validated['excerpt_html'];
            $row->excerpt_html = $raw !== null ? trim((string) $raw) : null;
        }

        if (array_key_exists('body_html', $validated)) {
            $raw = $validated['body_html'];
            $row->body_html = $raw !== null ? trim((string) $raw) : null;
        }

        if (array_key_exists('lesson_meta', $validated)) {
            $row->lesson_meta_json = LessonMetaSupport::sanitize($validated['lesson_meta']);
        }

        self::syncStandalonePlainSummaryIfNeeded($row);

        if (array_key_exists('summary', $validated)) {
            $row->summary = $validated['summary'] !== null ? trim((string) $validated['summary']) : null;
        }

        $row->save();

        LmsCatalogService::bustUserAnalyticsCache($actor->id);

        $parentModule = Module::query()
            ->with([
                'resources.lessonMaterials.moduleResource',
                'moduleLessonMaterials.moduleResource',
                'quizzes' => fn ($query) => $query->withCount('questions'),
                'course',
            ])
            ->whereKey($row->module_id)
            ->firstOrFail();

        return response()->json([
            'data' => $catalog->modulePayloadForUser($parentModule, $actor),
        ]);
    }

    /** Remove one standalone curriculum lesson (parent module unchanged). */
    public function destroyStandaloneLesson(string $publicId): JsonResponse
    {
        $actor = $this->lmsActor();

        $row = ModuleResource::query()
            ->where('public_id', $publicId)
            ->where('is_standalone_lesson', true)
            ->firstOrFail();

        $row->delete();

        LmsCatalogService::bustUserAnalyticsCache($actor->id);

        return response()->json(['ok' => true]);
    }

    /** Delete a curriculum module (instructor authoring; removes quizzes linked to this module). */
    public function destroy(string $publicId): JsonResponse
    {
        $actor = $this->lmsActor();

        /** @var Module $module */
        $module = Module::query()->where('public_id', $publicId)->firstOrFail();

        DB::transaction(function () use ($module) {
            Course::query()->where('next_module_id', $module->id)->update(['next_module_id' => null]);
            Quiz::query()->where('module_id', $module->id)->delete();
            Assignment::query()->where('module_id', $module->id)->delete();
            $module->delete();
        });

        LmsCatalogService::bustUserAnalyticsCache($actor->id);

        return response()->json(['ok' => true]);
    }

    public function toggleVisibility(string $publicId): JsonResponse
    {
        $module = Module::query()->where('public_id', $publicId)->firstOrFail();
        $module->update(['is_visible' => ! $module->is_visible]);

        $actor = $this->lmsActor();
        LmsCatalogService::bustUserAnalyticsCache($actor->id);

        return response()->json(['moduleId' => $module->public_id]);
    }

    /** Reorder modules in one course; persists `sort_order` and returns fresh module list. */
    public function reorder(Request $request, string $coursePublicId, LmsCatalogService $catalog): JsonResponse
    {
        $actor = $this->lmsActor();
        $course = Course::query()->where('public_id', $coursePublicId)->firstOrFail();

        $validated = $request->validate([
            'moduleIds' => ['required', 'array', 'min:1'],
            'moduleIds.*' => ['required', 'string', 'max:64'],
        ]);

        $requestedPublicIds = array_values(array_unique(array_map(
            fn ($id) => trim((string) $id),
            is_array($validated['moduleIds'] ?? null) ? $validated['moduleIds'] : []
        )));

        $rows = Module::query()
            ->where('course_id', $course->id)
            ->get(['id', 'public_id'])
            ->values();

        $dbIdByPublic = $rows->pluck('id', 'public_id');
        $orderedDbIds = [];

        foreach ($requestedPublicIds as $publicId) {
            $dbId = $dbIdByPublic->get($publicId);
            if ($dbId !== null) {
                $orderedDbIds[] = (int) $dbId;
            }
        }

        foreach ($rows as $row) {
            if (! in_array((int) $row->id, $orderedDbIds, true)) {
                $orderedDbIds[] = (int) $row->id;
            }
        }

        DB::transaction(function () use ($orderedDbIds) {
            foreach ($orderedDbIds as $idx => $moduleId) {
                Module::query()->whereKey($moduleId)->update(['sort_order' => $idx + 1]);
            }
        });

        LmsCatalogService::bustUserAnalyticsCache($actor->id);

        return response()->json([
            'data' => $catalog->modulesForCourse($actor, $coursePublicId),
        ]);
    }

    /** Reorder lessons inside one module (core lesson remains first in UI). */
    public function reorderLessons(Request $request, string $modulePublicId, LmsCatalogService $catalog): JsonResponse
    {
        $actor = $this->lmsActor();

        /** @var Module $module */
        $module = Module::query()->where('public_id', $modulePublicId)->firstOrFail();

        $validated = $request->validate([
            'lessonIds' => ['required', 'array', 'min:1'],
            'lessonIds.*' => ['required', 'string', 'max:64'],
        ]);

        $requested = array_values(array_unique(array_filter(array_map(
            fn ($id) => trim((string) $id),
            is_array($validated['lessonIds'] ?? null) ? $validated['lessonIds'] : []
        ))));

        $standalone = ModuleResource::query()
            ->where('module_id', $module->id)
            ->where('is_standalone_lesson', true)
            ->get(['id', 'public_id', 'sort_order']);
        $quizzes = Quiz::query()
            ->where('module_id', $module->id)
            ->get(['id', 'public_id', 'sort_order']);
        $assignments = Assignment::query()
            ->where('module_id', $module->id)
            ->get(['id', 'public_id', 'sort_order']);

        $all = collect()
            ->merge($standalone->map(fn ($r) => [
                'kind' => 'standalone',
                'id' => (int) $r->id,
                'public_id' => (string) $r->public_id,
                'sort_order' => (int) $r->sort_order,
            ]))
            ->merge($quizzes->map(fn ($q) => [
                'kind' => 'quiz',
                'id' => (int) $q->id,
                'public_id' => (string) $q->public_id,
                'sort_order' => (int) $q->sort_order,
            ]))
            ->merge($assignments->map(fn ($a) => [
                'kind' => 'assignment',
                'id' => (int) $a->id,
                'public_id' => (string) $a->public_id,
                'sort_order' => (int) $a->sort_order,
            ]));

        $rowByPublic = $all->keyBy('public_id');
        $requestedKnown = [];
        foreach ($requested as $publicId) {
            if (str_ends_with($publicId, '-core')) {
                continue;
            }
            if ($rowByPublic->has($publicId)) {
                $requestedKnown[] = $publicId;
            }
        }

        $remaining = $all
            ->filter(fn ($row) => ! in_array($row['public_id'], $requestedKnown, true))
            ->sortBy(fn ($row) => sprintf('%05d-%s-%09d', (int) $row['sort_order'], $row['kind'], (int) $row['id']))
            ->pluck('public_id')
            ->values()
            ->all();

        $finalPublicOrder = array_values(array_merge($requestedKnown, $remaining));

        DB::transaction(function () use ($finalPublicOrder, $rowByPublic) {
            foreach ($finalPublicOrder as $idx => $publicId) {
                $row = $rowByPublic->get($publicId);
                if (! is_array($row)) {
                    continue;
                }
                $nextSort = $idx + 1;
                if (($row['kind'] ?? '') === 'quiz') {
                    Quiz::query()->whereKey((int) $row['id'])->update(['sort_order' => $nextSort]);
                } elseif (($row['kind'] ?? '') === 'assignment') {
                    Assignment::query()->whereKey((int) $row['id'])->update(['sort_order' => $nextSort]);
                } else {
                    ModuleResource::query()->whereKey((int) $row['id'])->update(['sort_order' => $nextSort]);
                }
            }
        });

        LmsCatalogService::bustUserAnalyticsCache($actor->id);

        $fresh = Module::query()
            ->whereKey($module->id)
            ->with([
                'resources.lessonMaterials.moduleResource',
                'moduleLessonMaterials.moduleResource',
                'quizzes' => fn ($query) => $query->withCount('questions'),
                'assignments' => fn ($query) => $query->withCount('questions')->with('lessonMaterials'),
                'course',
            ])
            ->firstOrFail();

        return response()->json([
            'data' => $catalog->modulePayloadForUser($fresh, $actor),
        ]);
    }

    /** Partial module update for instructor authoring (metadata + rich lesson fields). */
    public function update(Request $request, string $publicId, LmsCatalogService $catalog): JsonResponse
    {
        $actor = $this->lmsActor();

        /** @var Module $module */
        $module = Module::query()
            ->where('public_id', $publicId)
            ->with(['resources', 'course'])
            ->firstOrFail();

        $validated = $request->validate([
            'title' => ['sometimes', 'string', 'max:512'],
            'subject' => ['sometimes', 'nullable', 'string', 'max:255'],
            'topic' => ['sometimes', 'nullable', 'string', 'max:255'],
            'summary' => ['sometimes', 'nullable', 'string'],
            'excerpt_html' => ['sometimes', 'nullable', 'string', 'max:65000'],
            'body_html' => ['sometimes', 'nullable', 'string', 'max:650000'],
            'duration_label' => ['sometimes', 'nullable', 'string', 'max:64'],
            'streaming_only' => ['sometimes', 'boolean'],
            'lesson_meta' => ['sometimes', 'nullable', 'array'],
        ]);

        if (array_key_exists('title', $validated)) {
            $module->title = trim((string) $validated['title']);
        }

        if (array_key_exists('subject', $validated)) {
            $module->subject = $validated['subject'] !== null ? trim((string) $validated['subject']) : null;
        }

        if (array_key_exists('topic', $validated)) {
            $module->topic = $validated['topic'] !== null ? trim((string) $validated['topic']) : null;
        }

        if (array_key_exists('excerpt_html', $validated)) {
            $raw = $validated['excerpt_html'];
            $module->excerpt_html = $raw !== null ? trim((string) $raw) : null;
        }

        if (array_key_exists('body_html', $validated)) {
            $raw = $validated['body_html'];
            $module->body_html = $raw !== null ? trim((string) $raw) : null;
        }

        if (array_key_exists('duration_label', $validated)) {
            $raw = $validated['duration_label'];
            $module->duration_label = $raw !== null && trim((string) $raw) !== '' ? trim((string) $raw) : null;
        }

        if (array_key_exists('streaming_only', $validated)) {
            $module->streaming_only = (bool) $validated['streaming_only'];
        }

        if (array_key_exists('lesson_meta', $validated)) {
            $module->lesson_meta_json = LessonMetaSupport::sanitize($validated['lesson_meta']);
        }

        self::syncModulePlainSummaryIfNeeded($module);

        if (array_key_exists('summary', $validated)) {
            $module->summary = $validated['summary'] !== null ? trim((string) $validated['summary']) : null;
        }

        $module->save();

        LmsCatalogService::bustUserAnalyticsCache($actor->id);

        return response()->json([
            'data' => $catalog->modulePayloadForUser(
                $module->fresh([
                    'resources.lessonMaterials.moduleResource',
                    'moduleLessonMaterials.moduleResource',
                    'quizzes' => fn ($query) => $query->withCount('questions'),
                    'course',
                ]),
                $actor
            ),
        ]);
    }

    protected static function syncModulePlainSummaryIfNeeded(Module $module): void
    {
        if (filled($module->body_html)) {
            $module->summary = Str::limit(strip_tags((string) $module->body_html), 62000);

            return;
        }
        if (filled($module->excerpt_html)) {
            $module->summary = Str::limit(strip_tags((string) $module->excerpt_html), 62000);

            return;
        }
        // Allow explicit legacy clears when both rich fields emptied
        if ($module->isDirty(['body_html', 'excerpt_html']) && ! filled($module->body_html ?? null) && ! filled($module->excerpt_html ?? null)) {
            $module->summary = null;
        }
    }

    protected static function syncStandalonePlainSummaryIfNeeded(ModuleResource $row): void
    {
        if (filled($row->body_html)) {
            $row->summary = Str::limit(strip_tags((string) $row->body_html), 62000);

            return;
        }
        if (filled($row->excerpt_html)) {
            $row->summary = Str::limit(strip_tags((string) $row->excerpt_html), 62000);

            return;
        }
        if ($row->isDirty(['body_html', 'excerpt_html']) && ! filled($row->body_html ?? null) && ! filled($row->excerpt_html ?? null)) {
            $row->summary = null;
        }
    }
}
