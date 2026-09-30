<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ResolvesLmsActor;
use App\Http\Controllers\Controller;
use App\Models\Assignment;
use App\Services\LmsCatalogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LmsAssignmentSummaryController extends Controller
{
    use ResolvesLmsActor;

    public function index(LmsCatalogService $catalog): JsonResponse
    {
        $actor = $this->lmsActor();

        if (! $catalog->userCanViewAssignmentSummaries($actor)) {
            abort(403, 'You do not have permission to view assignment summaries.');
        }

        return response()->json([
            'data' => $catalog->assignmentSummariesForStaff(),
        ]);
    }

    public function students(Request $request, string $publicId, LmsCatalogService $catalog): JsonResponse
    {
        $actor = $this->lmsActor();

        if (! $catalog->userCanViewAssignmentSummaries($actor)) {
            abort(403, 'You do not have permission to view assignment student progress.');
        }

        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'in:5,10,20,50,100'],
            'status' => ['sometimes', 'string', 'in:passed,non_passed,pending'],
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $assignment = Assignment::query()
            ->where('public_id', $publicId)
            ->with('course')
            ->firstOrFail();

        return response()->json(
            $catalog->assignmentStudentProgressPaginated(
                $assignment,
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

        $assignment = Assignment::query()
            ->where('public_id', $publicId)
            ->with('course')
            ->firstOrFail();

        if (! $catalog->userCanViewAssignmentLeaderboard($actor, $assignment)) {
            abort(403, 'You do not have permission to view this assignment leaderboard.');
        }

        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'in:5,10,20,30,50,100'],
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $useAliasNames = ! $catalog->userCanViewAssignmentSummaries($actor);

        return response()->json(
            $catalog->assignmentLeaderboardPaginated(
                $assignment,
                (int) ($validated['page'] ?? 1),
                (int) ($validated['per_page'] ?? ($useAliasNames ? 20 : 10)),
                isset($validated['search']) ? (string) $validated['search'] : null,
                $useAliasNames,
                $useAliasNames ? $actor : null
            )
        );
    }
}
