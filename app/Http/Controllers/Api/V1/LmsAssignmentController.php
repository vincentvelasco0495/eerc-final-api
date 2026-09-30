<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\Concerns\ResolvesLmsActor;
use App\Http\Controllers\Controller;
use App\Models\Assignment;
use App\Models\AssignmentAttempt;
use App\Models\AssignmentQuestion;
use App\Models\AssignmentQuestionOption;
use App\Models\LessonMaterial;
use App\Models\Module;
use App\Services\LmsCatalogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class LmsAssignmentController extends Controller
{
    use ResolvesLmsActor;

    public function storeForModule(Request $request, string $modulePublicId, LmsCatalogService $catalog): JsonResponse
    {
        $actor = $this->lmsActor();

        /** @var Module $module */
        $module = Module::query()->where('public_id', $modulePublicId)->with('course')->firstOrFail();

        $validated = $request->validate([
            'title' => ['sometimes', 'nullable', 'string', 'max:512'],
        ]);

        $title = isset($validated['title']) ? trim((string) $validated['title']) : '';
        if ($title === '') {
            $title = 'New assignment';
        }

        $maxSortOrder = (int) Assignment::query()->where('module_id', $module->id)->max('sort_order');

        $assignment = Assignment::query()->create([
            'public_id' => (string) Str::uuid(),
            'course_id' => $module->course_id,
            'module_id' => $module->id,
            'title' => $title,
            'duration_minutes' => 600,
            'attempts_allowed' => 2,
            'sort_order' => $maxSortOrder + 1,
            'question_count' => 0,
            'settings_json' => [
                'timeUnit' => 'hours',
                'resetTimeLimitOnRetake' => false,
            ],
        ]);

        LmsCatalogService::bustUserAnalyticsCache($actor->id);

        return response()->json([
            'data' => $catalog->authoringAssignmentPayload($assignment->fresh(['course', 'module', 'lessonMaterials']), $actor),
        ], 201);
    }

    public function questions(string $publicId, LmsCatalogService $catalog): JsonResponse
    {
        $user = $this->lmsActor();
        $assignment = Assignment::query()
            ->where('public_id', $publicId)
            ->with(['questions.options'])
            ->firstOrFail();

        $items = $assignment->questions
            ->map(fn (AssignmentQuestion $q) => $catalog->formatAssignmentQuestion($q))
            ->values()
            ->all();

        return response()->json($items);
    }

    public function update(Request $request, string $publicId, LmsCatalogService $catalog): JsonResponse
    {
        $actor = $this->lmsActor();
        /** @var Assignment $assignment */
        $assignment = Assignment::query()
            ->where('public_id', $publicId)
            ->with(['course', 'module', 'lessonMaterials'])
            ->firstOrFail();

        $validated = $request->validate([
            'title' => ['sometimes', 'nullable', 'string', 'max:512'],
            'lessonContentHtml' => ['sometimes', 'nullable', 'string', 'max:16777215'],
            'attemptsAllowed' => ['sometimes', 'integer', 'min:1', 'max:255'],
            'duration' => ['sometimes', 'integer', 'min:0', 'max:525600'],
            'timeUnit' => ['sometimes', 'nullable', 'in:minutes,hours'],
            'resetTimeLimitOnRetake' => ['sometimes', 'boolean'],
            'lessonPreview' => ['sometimes', 'boolean'],
            'questions' => ['sometimes', 'array'],
            'questions.*.prompt' => ['required_with:questions', 'string'],
            'questions.*.choices' => ['required_with:questions', 'array', 'min:2'],
            'questions.*.choices.*.label' => ['required_with:questions', 'string', 'max:2048'],
            'questions.*.choices.*.isCorrect' => ['sometimes', 'boolean'],
        ]);

        DB::transaction(function () use ($assignment, $validated) {
            if (array_key_exists('title', $validated)) {
                $t = trim((string) ($validated['title'] ?? ''));
                if ($t !== '') {
                    $assignment->title = $t;
                }
            }

            if (array_key_exists('lessonContentHtml', $validated)) {
                $assignment->content_html = (string) ($validated['lessonContentHtml'] ?? '');
            }

            if (array_key_exists('attemptsAllowed', $validated)) {
                $assignment->attempts_allowed = (int) $validated['attemptsAllowed'];
            }

            $settings = is_array($assignment->settings_json) ? $assignment->settings_json : [];
            $settingsDirty = false;

            if (array_key_exists('timeUnit', $validated) && in_array($validated['timeUnit'], ['minutes', 'hours'], true)) {
                $settings['timeUnit'] = (string) $validated['timeUnit'];
                $settingsDirty = true;
            }

            if (array_key_exists('resetTimeLimitOnRetake', $validated)) {
                $settings['resetTimeLimitOnRetake'] = (bool) $validated['resetTimeLimitOnRetake'];
                $settingsDirty = true;
            }

            if (array_key_exists('lessonPreview', $validated)) {
                $settings['lessonPreview'] = (bool) $validated['lessonPreview'];
                $settingsDirty = true;
            }

            if (array_key_exists('duration', $validated)) {
                $d = max(0, (int) $validated['duration']);
                $tuForCalc = (($settings['timeUnit'] ?? 'minutes') === 'hours') ? 'hours' : 'minutes';
                $assignment->duration_minutes = $tuForCalc === 'hours' ? min(525600, $d * 60) : min(525600, $d);
            }

            if ($settingsDirty) {
                $assignment->settings_json = $settings;
            }

            if (array_key_exists('questions', $validated)) {
                $incoming = is_array($validated['questions']) ? $validated['questions'] : [];

                AssignmentQuestion::query()->where('assignment_id', $assignment->id)->delete();

                foreach ($incoming as $qIndex => $qRow) {
                    $prompt = trim((string) ($qRow['prompt'] ?? ''));
                    if ($prompt === '') {
                        continue;
                    }

                    $question = AssignmentQuestion::query()->create([
                        'assignment_id' => $assignment->id,
                        'prompt' => $prompt,
                        'question_type' => 'single_choice',
                        'sort_order' => $qIndex + 1,
                    ]);

                    $choices = is_array($qRow['choices'] ?? null) ? $qRow['choices'] : [];
                    foreach ($choices as $cIndex => $cRow) {
                        $label = trim((string) ($cRow['label'] ?? ''));
                        if ($label === '') {
                            continue;
                        }
                        AssignmentQuestionOption::query()->create([
                            'assignment_question_id' => $question->id,
                            'label' => $label,
                            'is_correct' => (bool) ($cRow['isCorrect'] ?? false),
                            'sort_order' => $cIndex + 1,
                        ]);
                    }
                }

                $assignment->question_count = (int) AssignmentQuestion::query()
                    ->where('assignment_id', $assignment->id)
                    ->count();
            }

            $assignment->save();
        });

        LmsCatalogService::bustUserAnalyticsCache($actor->id);

        return response()->json([
            'data' => $catalog->authoringAssignmentPayload(
                $assignment->fresh(['course', 'module', 'lessonMaterials'])->loadCount('questions'),
                $actor
            ),
        ]);
    }

    public function destroy(string $publicId): JsonResponse
    {
        $actor = $this->lmsActor();

        $assignment = Assignment::query()->where('public_id', $publicId)->firstOrFail();

        DB::transaction(function () use ($assignment) {
            $materials = LessonMaterial::query()->where('assignment_id', $assignment->id)->get();
            foreach ($materials as $material) {
                if ($material->storage_path && Storage::disk('public')->exists($material->storage_path)) {
                    Storage::disk('public')->delete($material->storage_path);
                }
                $material->delete();
            }

            $assignment->delete();
        });

        LmsCatalogService::bustUserAnalyticsCache($actor->id);

        return response()->json(['ok' => true]);
    }

    public function storeAttempt(Request $request, string $publicId, LmsCatalogService $catalog): JsonResponse
    {
        $user = $this->lmsActor();
        $assignment = Assignment::query()
            ->where('public_id', $publicId)
            ->with(['course'])
            ->withCount('questions')
            ->firstOrFail();

        if ($assignment->course !== null) {
            if ($message = $catalog->curriculumAccessDeniedMessage($user, $assignment->course)) {
                return response()->json(['message' => $message], 403);
            }

            if ($catalog->isCurriculumItemLockedForUser($user, $assignment->course, $assignment->public_id)) {
                return response()->json(['message' => 'Complete earlier lessons before attempting this assignment.'], 403);
            }
        }

        $validated = $request->validate([
            'selections' => ['required', 'array'],
            'selections.*' => ['nullable', 'string', 'max:64'],
            'durationUsedSeconds' => ['sometimes', 'integer', 'min:0', 'max:31536000'],
        ]);

        $used = AssignmentAttempt::query()
            ->where('user_id', $user->id)
            ->where('assignment_id', $assignment->id)
            ->count();
        if ((int) $assignment->attempts_allowed > 0 && $used >= (int) $assignment->attempts_allowed) {
            return response()->json(['message' => 'No attempts remaining for this assignment.'], 422);
        }

        $selections = is_array($validated['selections'] ?? null) ? $validated['selections'] : [];
        $questions = AssignmentQuestion::query()
            ->where('assignment_id', $assignment->id)
            ->with(['options:id,assignment_question_id,is_correct'])
            ->get(['id']);

        $total = (int) max(1, $questions->count());
        $correct = 0;
        foreach ($questions as $question) {
            $selected = $selections[(string) $question->id] ?? null;
            if ($selected === null || $selected === '') {
                continue;
            }
            $picked = $question->options->first(
                fn (AssignmentQuestionOption $opt) => (string) $opt->id === (string) $selected
            );
            if ($picked?->is_correct) {
                $correct++;
            }
        }

        $score = (int) round(($correct / $total) * 100);
        $durationUsedSeconds = (int) ($validated['durationUsedSeconds'] ?? 0);
        $maxDurationSeconds = max(0, (int) $assignment->duration_minutes * 60);
        if ($maxDurationSeconds > 0) {
            $durationUsedSeconds = min($durationUsedSeconds, $maxDurationSeconds);
        }

        $attempt = AssignmentAttempt::query()->create([
            'public_id' => 'attempt-'.Str::lower(Str::ulid()),
            'user_id' => $user->id,
            'assignment_id' => $assignment->id,
            'attempted_on' => now()->toDateString(),
            'score' => $score,
            'duration_used_label' => $this->formatDurationLabel($durationUsedSeconds),
            'duration_used_seconds' => $durationUsedSeconds,
            'correct_answers' => $correct,
            'total_questions' => $total,
        ]);

        LmsCatalogService::bustUserAnalyticsCache($user->id);

        return response()->json([
            'id' => $attempt->public_id,
            'assignmentId' => $assignment->public_id,
            'date' => $attempt->attempted_on->format('Y-m-d'),
            'score' => (int) $attempt->score,
            'durationUsed' => $attempt->duration_used_label,
            'correctAnswers' => (int) $attempt->correct_answers,
            'totalQuestions' => (int) $attempt->total_questions,
        ], 201);
    }

    private function formatDurationLabel(int $seconds): string
    {
        $safe = max(0, $seconds);
        $m = intdiv($safe, 60);
        $s = $safe % 60;

        return sprintf('%dm %02ds', $m, $s);
    }
}
