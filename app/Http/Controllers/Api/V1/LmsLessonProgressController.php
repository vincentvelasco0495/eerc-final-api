<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\Concerns\ResolvesLmsActor;
use App\Http\Controllers\Controller;
use App\Models\Assignment;
use App\Models\Course;
use App\Models\Module;
use App\Models\ModuleResource;
use App\Models\UserLessonProgress;
use App\Services\LmsCatalogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LmsLessonProgressController extends Controller
{
    use ResolvesLmsActor;

    public function index(string $coursePublicId): JsonResponse
    {
        $user = $this->lmsActor();
        $course = Course::query()->where('public_id', $coursePublicId)->firstOrFail();

        $rows = UserLessonProgress::query()
            ->where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->get();

        $completedKeys = $rows
            ->filter(fn (UserLessonProgress $row) => $row->completed_at !== null)
            ->map(fn (UserLessonProgress $row) => (string) $row->lesson_key)
            ->values()
            ->all();

        $records = $rows
            ->map(fn (UserLessonProgress $row) => [
                'lessonKey' => (string) $row->lesson_key,
                'progressPercent' => (int) ($row->progress_percent ?? 0),
                'lastPositionSeconds' => (int) ($row->last_position_seconds ?? 0),
                'completed' => $row->completed_at !== null,
            ])
            ->values()
            ->all();

        $presenceSeconds = max(15, (int) config('lms.video.presence_seconds', 90));
        $concurrentViewers = UserLessonProgress::query()
            ->where('course_id', $course->id)
            ->where('last_heartbeat_at', '>=', now()->subSeconds($presenceSeconds))
            ->selectRaw('lesson_key, COUNT(*) as viewer_count')
            ->groupBy('lesson_key')
            ->pluck('viewer_count', 'lesson_key')
            ->map(fn ($count) => (int) $count)
            ->all();

        return response()->json([
            'data' => $completedKeys,
            'records' => $records,
            'concurrentViewers' => $concurrentViewers,
        ]);
    }

    public function complete(Request $request, string $coursePublicId, LmsCatalogService $catalog): JsonResponse
    {
        $validated = $request->validate([
            'lessonKey' => ['required', 'string', 'max:96'],
        ]);

        return $this->upsertProgress($coursePublicId, $catalog, [
            'lessonKey' => trim((string) $validated['lessonKey']),
            'progressPercent' => 100,
            'completed' => true,
        ]);
    }

    public function heartbeat(Request $request, string $coursePublicId, LmsCatalogService $catalog): JsonResponse
    {
        $validated = $request->validate([
            'lessonKey' => ['required', 'string', 'max:96'],
            'progressPercent' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100'],
            'lastPositionSeconds' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:864000'],
            'completed' => ['sometimes', 'boolean'],
            'presence' => ['sometimes', 'boolean'],
        ]);

        return $this->upsertProgress($coursePublicId, $catalog, $validated);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    protected function upsertProgress(
        string $coursePublicId,
        LmsCatalogService $catalog,
        array $validated
    ): JsonResponse {
        $user = $this->lmsActor();
        $course = Course::query()->where('public_id', $coursePublicId)->firstOrFail();
        $lessonKey = trim((string) ($validated['lessonKey'] ?? ''));

        if ($lessonKey === '' || ! $this->lessonBelongsToCourse($course, $lessonKey)) {
            return response()->json(['message' => 'Lesson does not belong to this course.'], 422);
        }

        if ($message = $catalog->curriculumAccessDeniedMessage($user, $course)) {
            return response()->json(['message' => $message], 403);
        }

        if ($catalog->isCurriculumItemLockedForUser($user, $course, $lessonKey)) {
            return response()->json(['message' => 'Complete earlier lessons before accessing this one.'], 403);
        }

        $existing = UserLessonProgress::query()
            ->where('user_id', $user->id)
            ->where('lesson_key', $lessonKey)
            ->first();

        $incomingPercent = array_key_exists('progressPercent', $validated) && $validated['progressPercent'] !== null
            ? (int) $validated['progressPercent']
            : (int) ($existing?->progress_percent ?? 0);
        $completeAt = max(50, min(100, (int) config('lms.video.complete_at_percent', 90)));
        $markComplete = (bool) ($validated['completed'] ?? false) || $incomingPercent >= $completeAt;
        $percent = max((int) ($existing?->progress_percent ?? 0), $incomingPercent);
        if ($markComplete) {
            $percent = max($percent, 100);
        }

        $position = (int) ($existing?->last_position_seconds ?? 0);
        if (array_key_exists('lastPositionSeconds', $validated) && $validated['lastPositionSeconds'] !== null) {
            $position = (int) $validated['lastPositionSeconds'];
        }

        $row = UserLessonProgress::query()->updateOrCreate(
            [
                'user_id' => $user->id,
                'lesson_key' => $lessonKey,
            ],
            [
                'course_id' => $course->id,
                'progress_percent' => $percent,
                'last_position_seconds' => $position,
                'last_heartbeat_at' => now(),
                'completed_at' => $markComplete
                    ? ($existing?->completed_at ?? now())
                    : $existing?->completed_at,
            ]
        );

        $presenceSeconds = max(15, (int) config('lms.video.presence_seconds', 90));
        $watchingNow = (int) UserLessonProgress::query()
            ->where('course_id', $course->id)
            ->where('lesson_key', $lessonKey)
            ->where('last_heartbeat_at', '>=', now()->subSeconds($presenceSeconds))
            ->count();

        return response()->json([
            'ok' => true,
            'progressPercent' => (int) $row->progress_percent,
            'lastPositionSeconds' => (int) $row->last_position_seconds,
            'completed' => $row->completed_at !== null,
            'watchingNow' => $watchingNow,
        ]);
    }

    private function lessonBelongsToCourse(Course $course, string $lessonKey): bool
    {
        if ($lessonKey === '') {
            return false;
        }

        if (str_ends_with($lessonKey, '-core')) {
            $modulePublicId = substr($lessonKey, 0, -5);

            return Module::query()
                ->where('public_id', $modulePublicId)
                ->where('course_id', $course->id)
                ->exists();
        }

        return ModuleResource::query()
            ->where('public_id', $lessonKey)
            ->where('is_standalone_lesson', true)
            ->whereIn('lesson_kind', ['document', 'video', 'stream', 'zoom'])
            ->whereHas('module', fn ($q) => $q->where('course_id', $course->id))
            ->exists()
            || Assignment::query()
                ->where('public_id', $lessonKey)
                ->where('course_id', $course->id)
                ->exists();
    }
}
