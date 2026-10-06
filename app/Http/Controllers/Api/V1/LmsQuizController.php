<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\Concerns\ResolvesLmsActor;
use App\Http\Controllers\Controller;
use App\Models\Module;
use App\Models\Question;
use App\Models\LessonMaterial;
use App\Models\QuestionOption;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Services\LmsCatalogService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class LmsQuizController extends Controller
{
    use ResolvesLmsActor;

    public function index(Request $request, LmsCatalogService $catalog): JsonResponse
    {
        $user = $this->lmsActor();
        $moduleId = $request->query('moduleId');
        $modulePublicId = is_string($moduleId) && trim($moduleId) !== '' ? trim($moduleId) : null;
        $rows = $catalog->quizzesForModuleFilter($user, $modulePublicId);

        return response()->json(['data' => $rows]);
    }

    /** Attach a new quiz lesson to this module (`module_id`, `course_id` from LMS module row). */
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
            $title = 'New quiz';
        }

        $maxSortOrder = (int) Quiz::query()->where('module_id', $module->id)->max('sort_order');

        $quiz = Quiz::query()->create([
            'public_id' => (string) Str::uuid(),
            'course_id' => $module->course_id,
            'module_id' => $module->id,
            'title' => $title,
            'duration_minutes' => 15,
            'attempts_allowed' => 3,
            'sort_order' => $maxSortOrder + 1,
            'question_count' => 0,
            'question_pool_count' => 0,
        ]);

        LmsCatalogService::bustUserAnalyticsCache($actor->id);

        return response()->json([
            'data' => $catalog->authoringQuizPayload($quiz->fresh(['course', 'module']), $actor),
        ], 201);
    }

    public function questions(string $publicId, LmsCatalogService $catalog): JsonResponse
    {
        $user = $this->lmsActor();
        $quiz = Quiz::query()->where('public_id', $publicId)->with(['course', 'questions.options'])->firstOrFail();

        if ($quiz->course !== null) {
            if ($message = $catalog->curriculumAccessDeniedMessage($user, $quiz->course, 'quiz')) {
                return response()->json(['message' => $message], 403);
            }

            if ($catalog->isCurriculumItemLockedForUser($user, $quiz->course, $quiz->public_id)) {
                return response()->json(['message' => 'Complete earlier lessons before attempting this quiz.'], 403);
            }
        }

        $items = $quiz->questions
            ->map(fn (Question $q) => $catalog->formatQuizQuestion($q, $user))
            ->values()
            ->all();

        return response()->json($items);
    }

    /**
     * Persist quiz authoring payload (`title` + full question list) for instructor builder.
     * Replaces existing questions/options in one transaction.
     */
    public function update(Request $request, string $publicId, LmsCatalogService $catalog): JsonResponse
    {
        $actor = $this->lmsActor();
        /** @var Quiz $quiz */
        $quiz = Quiz::query()->where('public_id', $publicId)->with(['course', 'module'])->firstOrFail();

        $validated = $request->validate([
            'title' => ['sometimes', 'nullable', 'string', 'max:512'],
            'questions' => ['sometimes', 'array'],
            'questions.*.prompt' => ['required_with:questions', 'string'],
            'questions.*.questionType' => ['sometimes', 'string', 'in:single_choice,simulation_diagram'],
            'questions.*.required' => ['sometimes', 'boolean'],
            'questions.*.diagramMaterialPublicId' => ['sometimes', 'nullable', 'string', 'max:64'],
            'questions.*.problemImageMaterialPublicId' => ['sometimes', 'nullable', 'string', 'max:64'],
            'questions.*.solutionImageMaterialPublicId' => ['sometimes', 'nullable', 'string', 'max:64'],
            'questions.*.itemNumber' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:50'],
            'questions.*.choices' => ['required_with:questions', 'array', 'min:2'],
            'questions.*.choices.*.label' => ['required_with:questions', 'string', 'max:2048'],
            'questions.*.choices.*.isCorrect' => ['sometimes', 'boolean'],
            'shortDescription' => ['sometimes', 'nullable', 'string', 'max:65000'],
            'duration' => ['sometimes', 'integer', 'min:0', 'max:525600'],
            'timeUnit' => ['sometimes', 'nullable', 'in:minutes,hours'],
            'quizStyle' => ['sometimes', 'nullable', 'string', 'max:64'],
            'attemptsAllowed' => ['sometimes', 'integer', 'min:1', 'max:255'],
            'passingGrade' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'randomizeQuestions' => ['sometimes', 'boolean'],
            'randomizeAnswers' => ['sometimes', 'boolean'],
            'showCorrectAnswer' => ['sometimes', 'boolean'],
            'quizAttemptHistory' => ['sometimes', 'boolean'],
            'retakeAfterPass' => ['sometimes', 'boolean'],
            'limitedRetakeAttempts' => ['sometimes', 'boolean'],
        ]);

        DB::transaction(function () use ($quiz, $validated) {
            if (array_key_exists('title', $validated)) {
                $t = trim((string) ($validated['title'] ?? ''));
                if ($t !== '') {
                    $quiz->title = $t;
                }
            }

            $this->applyQuizAuthoringMeta($quiz, $validated);

            if (array_key_exists('questions', $validated)) {
                $incoming = is_array($validated['questions']) ? $validated['questions'] : [];

                Question::query()->where('quiz_id', $quiz->id)->delete();
                $quizImageMaterialIds = [];

                foreach ($incoming as $qIndex => $qRow) {
                    $prompt = trim((string) ($qRow['prompt'] ?? ''));
                    if ($prompt === '') {
                        continue;
                    }

                    $questionType = (($qRow['questionType'] ?? 'single_choice') === 'simulation_diagram')
                        ? 'simulation_diagram'
                        : 'single_choice';

                    $meta = [];
                    if (array_key_exists('required', $qRow)) {
                        $meta['required'] = (bool) $qRow['required'];
                    }
                    $problemImageId = isset($qRow['problemImageMaterialPublicId'])
                        ? trim((string) $qRow['problemImageMaterialPublicId'])
                        : (isset($qRow['diagramMaterialPublicId'])
                            ? trim((string) $qRow['diagramMaterialPublicId'])
                            : '');
                    if ($problemImageId !== '') {
                        $meta['problemImageMaterialPublicId'] = $problemImageId;
                        $quizImageMaterialIds[] = $problemImageId;
                    }

                    $solutionImageId = isset($qRow['solutionImageMaterialPublicId'])
                        ? trim((string) $qRow['solutionImageMaterialPublicId'])
                        : '';
                    if ($solutionImageId !== '') {
                        $meta['solutionImageMaterialPublicId'] = $solutionImageId;
                        $quizImageMaterialIds[] = $solutionImageId;
                    }

                    $itemNumber = isset($qRow['itemNumber']) ? (int) $qRow['itemNumber'] : 0;
                    if ($itemNumber > 0) {
                        $meta['itemNumber'] = $itemNumber;
                    }

                    $question = Question::query()->create([
                        'quiz_id' => $quiz->id,
                        'prompt' => $prompt,
                        'question_type' => $questionType,
                        'meta_json' => $meta !== [] ? $meta : null,
                        'sort_order' => $qIndex + 1,
                    ]);

                    $choices = is_array($qRow['choices'] ?? null) ? $qRow['choices'] : [];
                    foreach ($choices as $cIndex => $cRow) {
                        $label = trim((string) ($cRow['label'] ?? ''));
                        if ($label === '') {
                            continue;
                        }
                        QuestionOption::query()->create([
                            'question_id' => $question->id,
                            'label' => $label,
                            'is_correct' => (bool) ($cRow['isCorrect'] ?? false),
                            'sort_order' => $cIndex + 1,
                        ]);
                    }
                }

                $questionCount = (int) Question::query()->where('quiz_id', $quiz->id)->count();
                $quiz->question_count = $questionCount;
                $quiz->question_pool_count = $questionCount;

                $quizImageMaterialIds = array_values(array_unique(array_filter($quizImageMaterialIds)));
                if ($quizImageMaterialIds !== [] && Schema::hasColumn('lesson_materials', 'usage')) {
                    LessonMaterial::query()
                        ->whereIn('public_id', $quizImageMaterialIds)
                        ->update(['usage' => 'quiz_question_image']);
                }
            }

            $quiz->save();
        });

        LmsCatalogService::bustUserAnalyticsCache($actor->id);

        return response()->json([
            'data' => $catalog->authoringQuizPayload($quiz->fresh(['course', 'module']), $actor),
        ]);
    }

    /**
     * Persist instructor “Settings” tab fields (description, duration, JSON flags).
     *
     * @param  array<string, mixed>  $validated
     */
    private function applyQuizAuthoringMeta(Quiz $quiz, array $validated): void
    {
        if (array_key_exists('shortDescription', $validated)) {
            $quiz->description = (string) ($validated['shortDescription'] ?? '');
        }
        if (array_key_exists('attemptsAllowed', $validated)) {
            $quiz->attempts_allowed = (int) $validated['attemptsAllowed'];
        }

        $settings = is_array($quiz->settings_json) ? $quiz->settings_json : [];
        $settingsDirty = false;

        $toggleKeys = [
            'randomizeQuestions',
            'randomizeAnswers',
            'showCorrectAnswer',
            'quizAttemptHistory',
            'retakeAfterPass',
            'limitedRetakeAttempts',
        ];
        foreach ($toggleKeys as $key) {
            if (array_key_exists($key, $validated)) {
                $settings[$key] = (bool) $validated[$key];
                $settingsDirty = true;
            }
        }

        if (array_key_exists('quizStyle', $validated) && $validated['quizStyle'] !== null) {
            $s = trim((string) $validated['quizStyle']);
            $settings['quizStyle'] = $s !== '' ? $s : 'global';
            $settingsDirty = true;
        }
        if (array_key_exists('passingGrade', $validated)) {
            $settings['passingGrade'] = max(0, min(100, (int) $validated['passingGrade']));
            $settingsDirty = true;
        }
        if (array_key_exists('pointsCutAfterRetake', $settings)) {
            unset($settings['pointsCutAfterRetake']);
            $settingsDirty = true;
        }

        if (array_key_exists('timeUnit', $validated) && in_array($validated['timeUnit'], ['minutes', 'hours'], true)) {
            $settings['timeUnit'] = (string) $validated['timeUnit'];
            $settingsDirty = true;
        }

        if (array_key_exists('duration', $validated)) {
            $d = max(0, (int) $validated['duration']);
            $tuForCalc = (($settings['timeUnit'] ?? 'minutes') === 'hours') ? 'hours' : 'minutes';
            $quiz->duration_minutes = $tuForCalc === 'hours' ? min(525600, $d * 60) : min(525600, $d);
        }

        if ($settingsDirty) {
            $quiz->settings_json = $settings;
        }
    }

    public function storeAttempt(Request $request, string $publicId, LmsCatalogService $catalog): JsonResponse
    {
        $user = $this->lmsActor();
        $quiz = Quiz::query()->where('public_id', $publicId)->with(['course'])->withCount('questions')->firstOrFail();

        if ($quiz->course !== null) {
            if ($message = $catalog->curriculumAccessDeniedMessage($user, $quiz->course, 'quiz')) {
                return response()->json(['message' => $message], 403);
            }

            if ($catalog->isCurriculumItemLockedForUser($user, $quiz->course, $quiz->public_id)) {
                return response()->json(['message' => 'Complete earlier lessons before attempting this quiz.'], 403);
            }
        }

        $validated = $request->validate([
            'selections' => ['required', 'array'],
            'selections.*' => ['nullable', 'string', 'max:64'],
            'durationUsedSeconds' => ['sometimes', 'integer', 'min:0', 'max:31536000'],
        ]);

        $used = QuizAttempt::query()->where('user_id', $user->id)->where('quiz_id', $quiz->id)->count();
        $settings = is_array($quiz->settings_json) ? $quiz->settings_json : [];
        $limitedRetakes = (bool) ($settings['limitedRetakeAttempts'] ?? false);
        if (
            $limitedRetakes
            && (int) $quiz->attempts_allowed > 0
            && $used >= (int) $quiz->attempts_allowed
        ) {
            return response()->json(['message' => 'No attempts remaining for this quiz.'], 422);
        }

        $selections = is_array($validated['selections'] ?? null) ? $validated['selections'] : [];
        $questions = Question::query()
            ->where('quiz_id', $quiz->id)
            ->with(['options:id,question_id,is_correct'])
            ->get(['id']);

        $total = (int) max(1, $questions->count());
        $correct = 0;
        foreach ($questions as $question) {
            $selected = $selections[(string) $question->id] ?? null;
            if ($selected === null || $selected === '') {
                continue;
            }
            $picked = $question->options->first(fn (QuestionOption $opt) => (string) $opt->id === (string) $selected);
            if ($picked?->is_correct) {
                $correct++;
            }
        }
        $score = (int) round(($correct / $total) * 100);
        $durationUsedSeconds = (int) ($validated['durationUsedSeconds'] ?? 0);
        $maxDurationSeconds = max(0, (int) $quiz->duration_minutes * 60);
        if ($maxDurationSeconds > 0) {
            $durationUsedSeconds = min($durationUsedSeconds, $maxDurationSeconds);
        }

        $attempt = QuizAttempt::query()->create([
            'public_id' => 'attempt-'.Str::lower(Str::ulid()),
            'user_id' => $user->id,
            'quiz_id' => $quiz->id,
            'attempted_on' => now()->toDateString(),
            'score' => $score,
            'duration_used_label' => $this->formatDurationLabel($durationUsedSeconds),
            'duration_used_seconds' => $durationUsedSeconds,
            'correct_answers' => $correct,
            'total_questions' => $total,
        ]);

        LmsCatalogService::bustUserAnalyticsCache($user->id);
        LmsCatalogService::bustLeaderboardCache();

        return response()->json([
            'id' => $attempt->public_id,
            'quizId' => $quiz->public_id,
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
