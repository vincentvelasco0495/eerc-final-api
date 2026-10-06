<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ResolvesLmsActor;
use App\Http\Controllers\Controller;
use App\Services\LmsCatalogService;
use App\Support\ExcelDownload;
use App\Support\ExportDateRange;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LmsQuizResultController extends Controller
{
    use ResolvesLmsActor;

    public function index(Request $request, LmsCatalogService $catalog): JsonResponse
    {
        $actor = $this->lmsActor();
        $userId = $request->query('userId');

        if ($userId !== null && $userId !== '' && (string) $userId !== $actor->public_uid) {
            abort(403, 'Cannot access quiz results for another user.');
        }

        return response()->json(['data' => $catalog->quizResultsForUser($actor)]);
    }

    public function export(Request $request, LmsCatalogService $catalog)
    {
        $actor = $this->lmsActor();
        $userId = $request->query('userId');

        if ($userId !== null && $userId !== '' && (string) $userId !== $actor->public_uid) {
            abort(403, 'Cannot export quiz results for another user.');
        }

        $validated = $request->validate(array_merge(ExportDateRange::rules(), [
            'quizId' => ['sometimes', 'nullable', 'string', 'max:64'],
        ]));
        [$from, $to] = ExportDateRange::extract($validated);

        return ExcelDownload::make(
            'Attempts',
            ['Date', 'Quiz', 'Score', 'Correct', 'Time used'],
            $catalog->quizResultsForExport(
                $actor,
                $from,
                $to,
                isset($validated['quizId']) ? (string) $validated['quizId'] : null
            ),
            'quiz-history'
        );
    }
}
