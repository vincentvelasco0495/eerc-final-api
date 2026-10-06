<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ResolvesLmsActor;
use App\Http\Controllers\Controller;
use App\Models\Quiz;
use App\Services\LmsCatalogService;
use App\Support\ExcelDownload;
use App\Support\ExportDateRange;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LmsQuizSummaryController extends Controller
{
    use ResolvesLmsActor;

    public function index(LmsCatalogService $catalog): JsonResponse
    {
        $actor = $this->lmsActor();

        if (! $catalog->userCanViewQuizSummaries($actor)) {
            abort(403, 'You do not have permission to view quiz summaries.');
        }

        return response()->json([
            'data' => $catalog->quizSummariesForStaff(),
        ]);
    }

    public function students(Request $request, string $publicId, LmsCatalogService $catalog): JsonResponse
    {
        $actor = $this->lmsActor();

        if (! $catalog->userCanViewQuizSummaries($actor)) {
            abort(403, 'You do not have permission to view quiz student progress.');
        }

        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'in:5,10,20,50,100'],
            'status' => ['sometimes', 'string', 'in:passed,non_passed,pending'],
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $quiz = Quiz::query()
            ->where('public_id', $publicId)
            ->with('course')
            ->firstOrFail();

        return response()->json(
            $catalog->quizStudentProgressPaginated(
                $quiz,
                (string) ($validated['status'] ?? 'passed'),
                (int) ($validated['page'] ?? 1),
                (int) ($validated['per_page'] ?? 10),
                isset($validated['search']) ? (string) $validated['search'] : null
            )
        );
    }

    public function leaderboard(Request $request, string $publicId, LmsCatalogService $catalog): JsonResponse
    {
        $actor = $this->lmsActor();

        $quiz = Quiz::query()
            ->where('public_id', $publicId)
            ->with('course')
            ->firstOrFail();

        if (! $catalog->userCanViewQuizLeaderboard($actor, $quiz)) {
            abort(403, 'You do not have permission to view this quiz leaderboard.');
        }

        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'in:5,10,20,30,50,100'],
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $useAliasNames = ! $catalog->userCanViewQuizSummaries($actor);

        return response()->json(
            $catalog->quizLeaderboardPaginated(
                $quiz,
                (int) ($validated['page'] ?? 1),
                (int) ($validated['per_page'] ?? ($useAliasNames ? 20 : 10)),
                isset($validated['search']) ? (string) $validated['search'] : null,
                $useAliasNames,
                $useAliasNames ? $actor : null
            )
        );
    }

    public function export(Request $request, LmsCatalogService $catalog)
    {
        $actor = $this->lmsActor();

        if (! $catalog->userCanViewQuizSummaries($actor)) {
            abort(403, 'You do not have permission to export quiz summaries.');
        }

        $validated = $request->validate(array_merge(ExportDateRange::rules(), [
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'courseId' => ['sometimes', 'nullable', 'string', 'max:64'],
            'status' => ['sometimes', 'nullable', 'string', 'max:32'],
        ]));
        [$from, $to] = ExportDateRange::extract($validated);

        return ExcelDownload::make(
            'Quizzes',
            ['Quiz', 'Course', 'Total', 'Passed', 'Non passed', 'Pending'],
            $catalog->quizSummariesForExport(
                $from,
                $to,
                isset($validated['search']) ? (string) $validated['search'] : null,
                isset($validated['courseId']) ? (string) $validated['courseId'] : null,
                isset($validated['status']) ? (string) $validated['status'] : null
            ),
            'quizzes'
        );
    }

    public function exportStudents(Request $request, string $publicId, LmsCatalogService $catalog)
    {
        $actor = $this->lmsActor();

        if (! $catalog->userCanViewQuizSummaries($actor)) {
            abort(403, 'You do not have permission to export quiz student progress.');
        }

        $validated = $request->validate(array_merge(ExportDateRange::rules(), [
            'status' => ['sometimes', 'string', 'in:passed,non_passed,pending'],
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]));
        [$from, $to] = ExportDateRange::extract($validated);

        $quiz = Quiz::query()
            ->where('public_id', $publicId)
            ->with('course')
            ->firstOrFail();

        return ExcelDownload::make(
            'Students',
            ['Student', 'Email', 'Score', 'Attempted', 'Result'],
            $catalog->quizStudentProgressForExport(
                $quiz,
                (string) ($validated['status'] ?? 'passed'),
                isset($validated['search']) ? (string) $validated['search'] : null,
                $from,
                $to
            ),
            'quiz-students'
        );
    }

    public function exportLeaderboard(Request $request, string $publicId, LmsCatalogService $catalog)
    {
        $actor = $this->lmsActor();

        $quiz = Quiz::query()
            ->where('public_id', $publicId)
            ->with('course')
            ->firstOrFail();

        if (! $catalog->userCanViewQuizLeaderboard($actor, $quiz)) {
            abort(403, 'You do not have permission to export this quiz leaderboard.');
        }

        if (! $catalog->userCanViewQuizSummaries($actor)) {
            abort(403, 'You do not have permission to export this quiz leaderboard.');
        }

        $validated = $request->validate(array_merge(ExportDateRange::rules(), [
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]));
        [$from, $to] = ExportDateRange::extract($validated);
        $useAliasNames = ! $catalog->userCanViewQuizSummaries($actor);
        $headers = $useAliasNames
            ? ['Rank', 'Student', 'Score', 'Finish time', 'Result']
            : ['Rank', 'Student', 'Email', 'Score', 'Finish time', 'Result', 'Attempted'];

        return ExcelDownload::make(
            'Leaderboard',
            $headers,
            $catalog->quizLeaderboardForExport(
                $quiz,
                isset($validated['search']) ? (string) $validated['search'] : null,
                $useAliasNames,
                $useAliasNames ? $actor : null,
                $from,
                $to
            ),
            'quiz-leaderboard'
        );
    }
}
